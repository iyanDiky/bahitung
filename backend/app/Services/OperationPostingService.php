<?php

namespace App\Services;

use App\Exceptions\FinancialException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\GoalRepository;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\WalletRepository;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OperationPostingService
{
    public function __construct(private HouseholdRepository $households, private FinanceRepository $finance, private WalletRepository $wallets, private BudgetUsageService $budgetUsage, private GoalRepository $goals) {}

    public function list(User $user, int $householdId, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('view-operations', $this->households->find($householdId));

        return $this->finance->operations($householdId, $filters);
    }

    public function show(User $user, int $householdId, int $id): array
    {
        Gate::forUser($user)->authorize('view-operations', $this->households->find($householdId));

        return [...$this->finance->operation($householdId, $id), 'warnings' => []];
    }

    public function create(User $user, int $householdId, array $attributes): array
    {
        return $this->postRequest($user, $householdId, $attributes);
    }

    public function createGoal(User $user, int $householdId, int $goal, array $attributes, bool $withdraw): array
    {
        $attributes['type'] = $withdraw ? 'goal_withdrawal' : 'goal_contribution';
        $attributes['goal_id'] = $goal;

        return $this->postRequest($user, $householdId, $attributes, true);
    }

    private function postRequest(User $user, int $householdId, array $attributes, bool $goal = false): array
    {
        return DB::transaction(function () use ($user, $householdId, $attributes, $goal): array {
            $household = $this->households->find($householdId, lock: true);
            Gate::forUser($user)->authorize($goal && $attributes['type'] === 'goal_withdrawal' ? 'manage-goals' : 'post-operations', $household);
            if (! in_array($attributes['type'], $goal ? ['goal_contribution', 'goal_withdrawal'] : ['income', 'expense', 'transfer'], true)) {
                throw ValidationException::withMessages(['type' => ['Jenis transaksi tidak tersedia melalui posting biasa.']]);
            }
            if ($goal) {
                $this->goals->find($householdId, (int) $attributes['goal_id']);
            }
            $payload = $this->canonicalPayload($attributes);
            $endpoint = $goal ? ($attributes['type'] === 'goal_withdrawal' ? 'goals.withdrawals' : 'goals.contributions') : 'operations.store';
            $key = strtolower($attributes['idempotency_key']);
            $hash = hash('sha256', json_encode(['endpoint' => $endpoint, 'payload' => $payload], JSON_THROW_ON_ERROR));
            $receipt = $this->wallets->receipt($household, $key);
            if ($receipt) {
                if ($receipt->endpoint !== $endpoint || ! hash_equals($receipt->payload_hash, $hash)) {
                    throw new IdempotencyConflictException;
                }

                return json_decode($receipt->response_body, true, flags: JSON_THROW_ON_ERROR);
            }
            $deltas = $this->deltas($household, $payload);
            $changes = $this->postingChanges($householdId, $payload['occurred_on'], $deltas, false);
            $goalChanges = $this->postingChanges($householdId, $payload['occurred_on'], $this->goalDeltas($payload), true);
            $response = $this->finance->post($householdId, $this->households->membership($user, $household)->id, $payload, $key, $hash, $changes, $goalChanges);
            $response['warnings'] = $this->budgetUsage->warnings($householdId, [$payload]);
            $this->finance->remember($householdId, $key, $hash, $response, $endpoint);

            return $response;
        }, attempts: 3);
    }

    private function postingChanges(int $household, string $date, array $deltas, bool $goal): array
    {
        $states = $goal ? $this->goals->lock($household, array_keys($deltas)) : $this->finance->lockWallets($household, array_keys($deltas));
        $changes = [];
        foreach ($states as $id => $state) {
            if ($state['is_archived']) {
                throw new FinancialException($goal ? 'GOAL_ARCHIVED' : 'WALLET_ARCHIVED', $goal ? 'Aktifkan kembali target sebelum memindahkan dana.' : 'Dompet arsip tidak dapat dipakai untuk transaksi baru.');
            }
            $delta = $deltas[$id];
            $balance = BigDecimal::of($state['balance'])->plus($delta);
            if ($balance->isNegative()) {
                throw new FinancialException('INSUFFICIENT_BALANCE', $goal ? 'Saldo target tidak mencukupi.' : 'Saldo dompet tidak mencukupi, termasuk biaya admin.');
            }
            $bounds = $this->finance->timelineBounds($household, $id, [['date' => $date, 'delta' => (string) $delta]], $goal);
            if (BigDecimal::of($bounds->minimum)->isNegative()) {
                throw new FinancialException('HISTORICAL_BALANCE_CONFLICT', 'Transaksi akan membuat saldo historis negatif. Periksa tanggal dan saldo awal bersama Admin.');
            }
            if ($balance->isGreaterThan('99999999999999999.99') || $delta->abs()->isGreaterThan('99999999999999999.99') || BigDecimal::of($bounds->maximum)->isGreaterThan('99999999999999999.99')) {
                throw new FinancialException('INVALID_MONEY_AMOUNT', 'Nominal atau saldo melebihi batas yang didukung.');
            }
            $changes[$id] = ['delta' => (string) $delta->toScale(2), 'balance' => (string) $balance->toScale(2)];
        }

        return $changes;
    }

    public function goalDeltas(?array $payload): array
    {
        if (! $payload || ! in_array($payload['type'], ['goal_contribution', 'goal_withdrawal'], true)) {
            return [];
        }
        $amount = BigDecimal::of($payload['amount']);

        return [$payload['goal_id'] => $payload['type'] === 'goal_contribution' ? $amount : $amount->negated()];
    }

    public function canonicalPayload(array $attributes): array
    {
        $transfer = $attributes['type'] === 'transfer';
        $payload = ['type' => $attributes['type'], 'amount' => $attributes['amount'], 'occurred_on' => $attributes['occurred_on'], 'note' => $attributes['note'] ?? null];
        if ($transfer) {
            $payload += ['source_wallet_id' => (int) $attributes['source_wallet_id'], 'destination_wallet_id' => (int) $attributes['destination_wallet_id'], 'fee_amount' => $attributes['fee_amount'] ?? '0.00', 'fee_category_id' => isset($attributes['fee_category_id']) ? (int) $attributes['fee_category_id'] : null];
        } elseif (in_array($attributes['type'], ['goal_contribution', 'goal_withdrawal'], true)) {
            $field = $attributes['type'] === 'goal_contribution' ? 'wallet_id' : 'destination_wallet_id';
            $payload += ['goal_id' => (int) $attributes['goal_id'], $field => (int) $attributes[$field]];
        } elseif ($attributes['type'] === 'opening') {
            $payload += ['wallet_id' => (int) $attributes['wallet_id']];
        } else {
            $payload += ['wallet_id' => (int) $attributes['wallet_id'], 'category_id' => (int) $attributes['category_id']];
        }

        return $payload;
    }

    /** @return array<int, BigDecimal> */
    public function deltas(Household $household, array $payload): array
    {
        $householdId = $household->id;
        $transfer = $payload['type'] === 'transfer';
        if ($payload['occurred_on'] > now($household->timezone)->format('Y-m-d')) {
            throw ValidationException::withMessages(['occurred_on' => ['Tanggal transaksi tidak boleh di masa depan.']]);
        }
        $amount = BigDecimal::of($payload['amount']);
        if (! $amount->isPositive()) {
            throw new FinancialException('INVALID_MONEY_AMOUNT', 'Nominal transaksi harus lebih dari nol.');
        }
        if ($transfer) {
            if ($payload['source_wallet_id'] === $payload['destination_wallet_id']) {
                throw ValidationException::withMessages(['destination_wallet_id' => ['Dompet tujuan harus berbeda.']]);
            }
            $fee = BigDecimal::of($payload['fee_amount']);
            if ($fee->isNegative()) {
                throw new FinancialException('INVALID_MONEY_AMOUNT', 'Biaya admin tidak boleh negatif.');
            }
            if ($fee->isPositive() && $payload['fee_category_id'] === null) {
                throw ValidationException::withMessages(['fee_category_id' => ['Pilih kategori biaya admin.']]);
            }
            if ($payload['fee_category_id'] !== null) {
                $this->validateCategory($householdId, $payload['fee_category_id'], 'expense');
            }
            $deltas = [$payload['source_wallet_id'] => $amount->plus($fee)->negated(), $payload['destination_wallet_id'] => $amount];
        } elseif (in_array($payload['type'], ['goal_contribution', 'goal_withdrawal'], true)) {
            $this->goals->find($householdId, $payload['goal_id']);
            $deltas = $payload['type'] === 'goal_contribution' ? [$payload['wallet_id'] => $amount->negated()] : [$payload['destination_wallet_id'] => $amount];
        } else {
            if ($payload['type'] !== 'opening') {
                $this->validateCategory($householdId, $payload['category_id'], $payload['type']);
            }
            $deltas = [$payload['wallet_id'] => $payload['type'] === 'expense' ? $amount->negated() : $amount];
        }

        return $deltas;
    }

    private function validateCategory(int $household, int $id, string $type): void
    {
        $category = $this->finance->category($household, $id);
        if ($category->type !== $type || $category->is_archived) {
            throw new FinancialException('INVALID_CATEGORY_TYPE', 'Pilih kategori aktif dengan jenis yang sesuai.');
        }
    }
}
