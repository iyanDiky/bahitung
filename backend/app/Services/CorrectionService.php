<?php

namespace App\Services;

use App\Exceptions\FinancialException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\User;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\GoalRepository;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\WalletRepository;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CorrectionService
{
    public function __construct(private HouseholdRepository $households, private FinanceRepository $finance, private WalletRepository $wallets, private OperationPostingService $posting, private BudgetUsageService $budgetUsage, private GoalRepository $goals) {}

    public function apply(User $user, int $householdId, int $operationId, array $attributes, bool $correct): array
    {
        return DB::transaction(function () use ($user, $householdId, $operationId, $attributes, $correct): array {
            $household = $this->households->find($householdId, lock: true);
            Gate::forUser($user)->authorize('view-operations', $household);
            $original = $this->finance->operation($householdId, $operationId);
            Gate::forUser($user)->authorize('correct-operations', [$household, $original['data']]);
            $replacement = $correct ? $this->posting->canonicalPayload($attributes['replacement']) : null;
            $endpoint = $correct ? 'operations.correct' : 'operations.void';
            $key = strtolower($attributes['idempotency_key']);
            $hash = hash('sha256', json_encode(['endpoint' => $endpoint, 'operation_id' => $operationId, 'reason' => $attributes['reason'], 'replacement' => $replacement], JSON_THROW_ON_ERROR));
            $receipt = $this->wallets->receipt($household, $key);
            if ($receipt) {
                if ($receipt->endpoint !== $endpoint || ! hash_equals($receipt->payload_hash, $hash)) {
                    throw new IdempotencyConflictException;
                }

                return json_decode($receipt->response_body, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($original['data']['status'] === 'reversed') {
                throw new FinancialException('ALREADY_REVERSED', 'Transaksi ini sudah dibatalkan atau dikoreksi.', 409);
            }
            if (! in_array($original['data']['type'], ['opening', 'income', 'expense', 'transfer', 'goal_contribution', 'goal_withdrawal'], true)) {
                throw ValidationException::withMessages(['operation' => ['Jenis transaksi ini belum mendukung koreksi.']]);
            }
            if ($replacement && (($original['data']['type'] === 'opening') !== ($replacement['type'] === 'opening'))) {
                throw ValidationException::withMessages(['replacement.type' => ['Saldo awal hanya dapat diganti dengan saldo awal.']]);
            }
            if ($replacement && (in_array($original['data']['type'], ['goal_contribution', 'goal_withdrawal'], true) || in_array($replacement['type'], ['goal_contribution', 'goal_withdrawal'], true)) && $original['data']['type'] !== $replacement['type']) {
                throw ValidationException::withMessages(['replacement.type' => ['Koreksi target harus mempertahankan jenis setoran atau penarikan.']]);
            }
            $deltas = $replacement ? $this->posting->deltas($household, $replacement) : [];
            $goalDeltas = $this->posting->goalDeltas($replacement);
            $balances = $this->correctedBalances($householdId, $original, $replacement, $deltas, false);
            $goalBalances = $this->correctedBalances($householdId, $original, $replacement, $goalDeltas, true);
            $actor = $this->households->membership($user, $household)->id;
            $this->finance->reverse($householdId, $actor, $original, $attributes['reason']);
            $data = ['reversed_operation_id' => $operationId, 'status' => 'reversed'];
            if ($replacement) {
                $changes = [];
                foreach ($balances as $wallet => $balance) {
                    if (isset($deltas[$wallet])) {
                        $changes[$wallet] = ['delta' => (string) $deltas[$wallet]->toScale(2), 'balance' => $balance];
                    }
                }
                $posted = $this->finance->post($householdId, $actor, [...$replacement, 'correction_of_id' => $operationId], $key, $hash, $changes, $this->replacementChanges($goalBalances, $goalDeltas));
                $data = ['reversed_operation_id' => $operationId, 'replacement_operation_id' => $posted['data']['id'], 'result' => 'corrected'];
                $this->finance->audit($householdId, $actor, 'operation.corrected', $original['data'], [...$data, 'reason' => $attributes['reason']]);
            }
            $this->finance->updateBalances($householdId, $balances);
            $this->goals->updateBalances($householdId, $goalBalances);
            $response = ['data' => $data, 'goal_balances' => collect($goalBalances)->map(fn (string $balance, int $goal): array => ['goal_id' => $goal, 'balance' => $balance])->values()->all(), 'wallet_balances' => collect($balances)->map(fn (string $balance, int $wallet): array => ['wallet_id' => $wallet, 'balance' => $balance])->values()->all(), 'warnings' => []];
            $response['warnings'] = $this->budgetUsage->warnings($householdId, [$original['data'], $replacement]);
            $this->finance->remember($householdId, $key, $hash, $response, $endpoint, $correct ? 201 : 200);

            return $response;
        }, attempts: 3);
    }

    private function replacementChanges(array $balances, array $deltas): array
    {
        $changes = [];
        foreach ($balances as $id => $balance) {
            if (isset($deltas[$id])) {
                $changes[$id] = ['delta' => (string) $deltas[$id]->toScale(2), 'balance' => $balance];
            }
        }

        return $changes;
    }

    private function correctedBalances(int $household, array $original, ?array $replacement, array $deltas, bool $goal): array
    {
        $field = $goal ? 'goal_id' : 'wallet_id';
        $adjustments = [];
        foreach ($original['movements'] as $movement) {
            if ($movement[$field] !== null) {
                $adjustments[$movement[$field]][] = ['date' => $original['data']['occurred_on'], 'delta' => (string) BigDecimal::of($movement['amount_signed'])->negated()];
            }
        }
        foreach ($deltas as $id => $delta) {
            if ($delta->abs()->isGreaterThan('99999999999999999.99')) {
                throw new FinancialException('INVALID_MONEY_AMOUNT', 'Nominal melebihi batas yang didukung.');
            }
            $adjustments[$id][] = ['date' => $replacement['occurred_on'], 'delta' => (string) $delta];
        }
        $states = $goal ? $this->goals->lock($household, array_keys($adjustments)) : $this->finance->lockWallets($household, array_keys($adjustments));
        $balances = [];
        foreach ($states as $id => $state) {
            if (isset($deltas[$id]) && $state['is_archived']) {
                throw new FinancialException($goal ? 'GOAL_ARCHIVED' : 'WALLET_ARCHIVED', 'Transaksi pengganti harus memakai dompet dan target aktif.');
            }
            $balance = BigDecimal::of($state['balance']);
            foreach ($adjustments[$id] as $adjustment) {
                $balance = $balance->plus($adjustment['delta']);
            }
            $bounds = $this->finance->timelineBounds($household, $id, $adjustments[$id], $goal);
            if ($balance->isNegative() || BigDecimal::of($bounds->minimum)->isNegative()) {
                throw new FinancialException('HISTORICAL_BALANCE_CONFLICT', 'Koreksi membuat saldo saat ini atau saldo historis negatif. Periksa tanggal dan transaksi terkait bersama Admin.');
            }
            if ($balance->isGreaterThan('99999999999999999.99') || BigDecimal::of($bounds->maximum)->isGreaterThan('99999999999999999.99')) {
                throw new FinancialException('INVALID_MONEY_AMOUNT', 'Saldo hasil koreksi melebihi batas yang didukung.');
            }
            $balances[$id] = (string) $balance->toScale(2);
        }

        return $balances;
    }
}
