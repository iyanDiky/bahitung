<?php

namespace App\Repositories;

use App\Repositories\Contracts\FinanceRepository;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentFinanceRepository implements FinanceRepository
{
    public function categories(int $household, array $filters): LengthAwarePaginator
    {
        $query = DB::table('categories')->where('household_id', $household);
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_archived', $filters['status'] === 'archived');
        }

        return $query->orderBy('id')->paginate($filters['per_page'] ?? 20)->through(fn (object $row): object => $this->timestamps($row));
    }

    public function category(int $household, int $id): object
    {
        $category = DB::table('categories')->where('household_id', $household)->where('id', $id)->first();
        abort_unless($category, 404);

        return $this->timestamps($category);
    }

    public function categoryNameExists(int $household, string $type, string $name, ?int $except = null): bool
    {
        return DB::table('categories')->where('household_id', $household)->where('type', $type)->whereRaw('LOWER(name) = LOWER(?)', [$name])->when($except, fn (Builder $query) => $query->where('id', '<>', $except))->exists();
    }

    public function saveCategory(int $household, array $attributes, ?int $id = null): object
    {
        if ($id === null) {
            $id = DB::table('categories')->insertGetId([...$attributes, 'household_id' => $household, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('categories')->where('household_id', $household)->where('id', $id)->update([...$attributes, 'updated_at' => now()]);
        }

        return $this->category($household, $id);
    }

    public function audit(int $household, int $actor, string $action, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert(['household_id' => $household, 'actor_member_id' => $actor, 'action' => $action, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function lockWallets(int $household, array $ids): array
    {
        sort($ids, SORT_NUMERIC);
        $result = [];
        foreach ($ids as $id) {
            $wallet = DB::table('wallets')->where('household_id', $household)->where('id', $id)->first();
            abort_unless($wallet, 404);
            $projection = DB::table('wallet_balances')->where('household_id', $household)->where('wallet_id', $id)->lockForUpdate()->first();
            abort_unless($projection, 409);
            $result[$id] = ['balance' => $projection->balance, 'is_archived' => $wallet->is_archived];
        }

        return $result;
    }

    public function historicalMinimum(int $household, int $wallet, string $date): string
    {
        // Include the posting date even if there were no existing movements on it.
        $row = DB::selectOne('WITH daily AS (
            SELECT o.occurred_on, SUM(m.amount_signed) AS delta
            FROM balance_movements m JOIN operations o ON o.id = m.operation_id AND o.household_id = m.household_id
            WHERE m.household_id = ? AND m.wallet_id = ? GROUP BY o.occurred_on
            UNION ALL SELECT CAST(? AS date), 0::numeric
        ), running AS (
            SELECT occurred_on, SUM(SUM(delta)) OVER (ORDER BY occurred_on) AS balance FROM daily GROUP BY occurred_on
        ) SELECT MIN(balance)::text AS minimum FROM running WHERE occurred_on >= CAST(? AS date)', [$household, $wallet, $date, $date]);

        return $row->minimum;
    }

    public function post(int $household, int $actor, array $payload, string $key, string $hash, array $changes, array $goalChanges = []): array
    {
        $id = DB::table('operations')->insertGetId(['household_id' => $household, 'type' => $payload['type'], 'status' => 'posted', 'amount' => $payload['amount'], 'category_id' => $payload['category_id'] ?? null, 'goal_id' => $payload['goal_id'] ?? null, 'correction_of_id' => $payload['correction_of_id'] ?? null, 'occurred_on' => $payload['occurred_on'], 'note' => $payload['note'], 'created_by_member_id' => $actor, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        if ($payload['type'] === 'transfer') {
            DB::table('transfer_details')->insert(['operation_id' => $id, 'household_id' => $household, 'source_wallet_id' => $payload['source_wallet_id'], 'destination_wallet_id' => $payload['destination_wallet_id'], 'principal_amount' => $payload['amount'], 'fee_amount' => $payload['fee_amount'], 'fee_category_id' => $payload['fee_category_id']]);
        }
        $balances = [];
        foreach ($changes as $wallet => $change) {
            DB::table('balance_movements')->insert(['household_id' => $household, 'operation_id' => $id, 'wallet_id' => $wallet, 'amount_signed' => $change['delta'], 'entry_kind' => 'original', 'created_at' => now()]);
            DB::table('wallet_balances')->where('household_id', $household)->where('wallet_id', $wallet)->update(['balance' => $change['balance'], 'updated_at' => now()]);
            $balances[] = ['wallet_id' => $wallet, 'balance' => $change['balance']];
        }
        $goalBalances = [];
        foreach ($goalChanges as $goal => $change) {
            DB::table('balance_movements')->insert(['household_id' => $household, 'operation_id' => $id, 'goal_id' => $goal, 'amount_signed' => $change['delta'], 'entry_kind' => 'original', 'created_at' => now()]);
            DB::table('goal_balances')->where('household_id', $household)->where('goal_id', $goal)->update(['balance' => $change['balance'], 'updated_at' => now()]);
            $goalBalances[] = ['goal_id' => $goal, 'balance' => $change['balance']];
        }
        $response = [...$this->operation($household, $id), 'goal_balances' => $goalBalances, 'wallet_balances' => $balances, 'warnings' => []];
        $this->audit($household, $actor, 'operation.posted', null, $response);

        return $response;
    }

    public function remember(int $household, string $key, string $hash, array $response, string $endpoint = 'operations.store', int $status = 201): void
    {
        DB::table('idempotency_receipts')->insert(['household_id' => $household, 'key' => $key, 'payload_hash' => $hash, 'endpoint' => $endpoint, 'response_status' => $status, 'response_body' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function operationQuery(int $household): Builder
    {
        return DB::table('operations as o')->leftJoin('transfer_details as t', function ($join): void {
            $join->on('t.operation_id', '=', 'o.id')->on('t.household_id', '=', 'o.household_id');
        })
            ->where('o.household_id', $household)->select('o.id', 'o.household_id', 'o.type', 'o.status', 'o.amount', 'o.goal_id', 'o.category_id', 'o.correction_of_id', 'o.occurred_on', 'o.note', 'o.created_by_member_id', 'o.created_at', 'o.updated_at', 't.source_wallet_id', 't.destination_wallet_id', 't.fee_amount', 't.fee_category_id');
    }

    public function operations(int $household, array $filters): LengthAwarePaginator
    {
        $query = $this->operationQuery($household);
        foreach (['type', 'created_by_member_id', 'status', 'goal_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where('o.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['category_id'])) {
            $query->where(fn (Builder $q) => $q->where('o.category_id', $filters['category_id'])->orWhere('t.fee_category_id', $filters['category_id']));
        }
        if (isset($filters['from'])) {
            $query->where('o.occurred_on', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('o.occurred_on', '<=', $filters['to']);
        }
        if (isset($filters['wallet_id'])) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('balance_movements as m')->whereColumn('m.operation_id', 'o.id')->whereColumn('m.household_id', 'o.household_id')->where('m.wallet_id', $filters['wallet_id']));
        }

        return $query->orderBy('o.occurred_on', ($filters['sort'] ?? 'desc'))->orderBy('o.id', ($filters['sort'] ?? 'desc'))->paginate($filters['per_page'] ?? 20)->through(fn (object $row): object => $this->timestamps($row));
    }

    public function operation(int $household, int $id): array
    {
        $operation = $this->operationQuery($household)->where('o.id', $id)->first();
        abort_unless($operation, 404);

        $reversal = DB::table('operation_reversals')->where('household_id', $household)->where('operation_id', $id)->first(['created_by_member_id', 'reason', 'created_at']);
        if ($reversal) {
            $reversal->created_at = Carbon::parse($reversal->created_at)->utc()->toISOString();
            $reversal->replacement_operation_id = DB::table('operations')->where('household_id', $household)->where('correction_of_id', $id)->value('id');
        }

        return ['data' => (array) $this->timestamps($operation), 'reversal' => $reversal === null ? null : (array) $reversal, 'movements' => DB::table('balance_movements')->where('household_id', $household)->where('operation_id', $id)->orderBy('id')->get(['id', 'wallet_id', 'goal_id', 'amount_signed', 'entry_kind', 'reversal_of_id'])->map(fn (object $row): array => (array) $row)->all()];
    }

    public function timelineBounds(int $household, int $wallet, array $adjustments, bool $goal = false): object
    {
        $accountColumn = $goal ? 'goal_id' : 'wallet_id';
        $values = [];
        $bindings = [$household, $wallet];
        foreach ($adjustments as $adjustment) {
            $values[] = 'SELECT CAST(? AS date), CAST(? AS numeric)';
            $bindings[] = $adjustment['date'];
            $bindings[] = $adjustment['delta'];
        }

        return DB::selectOne('WITH daily AS (
            SELECT o.occurred_on, SUM(m.amount_signed) AS delta
            FROM balance_movements m JOIN operations o ON o.id = m.operation_id AND o.household_id = m.household_id
            WHERE m.household_id = ? AND m.'.$accountColumn.' = ? GROUP BY o.occurred_on
            UNION ALL '.implode(' UNION ALL ', $values).'
        ), running AS (
            SELECT occurred_on, SUM(SUM(delta)) OVER (ORDER BY occurred_on) AS balance FROM daily GROUP BY occurred_on
        ) SELECT MIN(balance)::text AS minimum, MAX(balance)::text AS maximum FROM running', $bindings);
    }

    public function reverse(int $household, int $actor, array $original, string $reason): void
    {
        $id = $original['data']['id'];
        DB::table('operation_reversals')->insert(['household_id' => $household, 'operation_id' => $id, 'created_by_member_id' => $actor, 'reason' => $reason, 'created_at' => now()]);
        foreach ($original['movements'] as $movement) {
            DB::table('balance_movements')->insert(['household_id' => $household, 'operation_id' => $id, 'wallet_id' => $movement['wallet_id'], 'goal_id' => $movement['goal_id'], 'amount_signed' => (string) BigDecimal::of($movement['amount_signed'])->negated(), 'entry_kind' => 'reversal', 'reversal_of_id' => $movement['id'], 'created_at' => now()]);
        }
        DB::table('operations')->where('household_id', $household)->where('id', $id)->update(['status' => 'reversed', 'updated_at' => now()]);
        $this->audit($household, $actor, 'operation.reversed', $original['data'], ['operation_id' => $id, 'status' => 'reversed', 'reason' => $reason]);
    }

    public function updateBalances(int $household, array $balances): void
    {
        foreach ($balances as $wallet => $balance) {
            DB::table('wallet_balances')->where('household_id', $household)->where('wallet_id', $wallet)->update(['balance' => $balance, 'updated_at' => now()]);
        }
    }

    private function timestamps(object $row): object
    {
        foreach (['created_at', 'updated_at'] as $field) {
            $row->{$field} = Carbon::parse($row->{$field})->utc()->toISOString();
        }

        return $row;
    }
}
