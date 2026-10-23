<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Wallet;
use App\Repositories\Contracts\WalletRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentWalletRepository implements WalletRepository
{
    private function query(Household $household): Builder
    {
        return Wallet::where('wallets.household_id', $household->id)->join('wallet_balances', function ($join): void {
            $join->on('wallet_balances.wallet_id', '=', 'wallets.id')->on('wallet_balances.household_id', '=', 'wallets.household_id');
        })->select('wallets.*', 'wallet_balances.balance');
    }

    public function list(Household $household, array $filters): LengthAwarePaginator
    {
        $query = $this->query($household);
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_archived', $filters['status'] === 'archived');
        }
        if (! empty($filters['q'])) {
            $query->whereRaw('POSITION(LOWER(?) IN LOWER(wallets.name)) > 0', [$filters['q']]);
        }

        return $query->orderBy('wallets.id')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function find(Household $household, int $id): Wallet
    {
        return $this->query($household)->where('wallets.id', $id)->firstOrFail();
    }

    public function create(Household $household, HouseholdMember $actor, array $attributes): Wallet
    {
        $wallet = Wallet::create(['household_id' => $household->id, 'created_by_member_id' => $actor->id, 'name' => $attributes['name'], 'type' => $attributes['type']]);
        DB::table('wallet_balances')->insert(['wallet_id' => $wallet->id, 'household_id' => $household->id, 'balance' => '0.00', 'updated_at' => now()]);

        return $wallet;
    }

    public function opening(Wallet $wallet, HouseholdMember $actor, string $amount, string $date, string $key, string $hash): void
    {
        DB::table('wallet_balances')->where('wallet_id', $wallet->id)->where('household_id', $wallet->household_id)->lockForUpdate()->firstOrFail();
        $operation = DB::table('operations')->insertGetId(['household_id' => $wallet->household_id, 'type' => 'opening', 'status' => 'posted', 'amount' => $amount,
            'occurred_on' => $date, 'note' => 'Saldo awal dompet', 'created_by_member_id' => $actor->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('balance_movements')->insert(['household_id' => $wallet->household_id, 'operation_id' => $operation, 'wallet_id' => $wallet->id, 'amount_signed' => $amount, 'entry_kind' => 'original', 'created_at' => now()]);
        DB::table('wallet_balances')->where('wallet_id', $wallet->id)->where('household_id', $wallet->household_id)->update(['balance' => $amount, 'updated_at' => now()]);
    }

    public function update(Wallet $wallet, array $attributes): void
    {
        $wallet->fill($attributes)->save();
    }

    public function movements(Wallet $wallet, array $filters): LengthAwarePaginator
    {
        return DB::table('balance_movements as m')->join('operations as o', function ($join): void {
            $join->on('o.id', '=', 'm.operation_id')->on('o.household_id', '=', 'm.household_id');
        })
            ->where('m.household_id', $wallet->household_id)->where('m.wallet_id', $wallet->id)
            ->when(isset($filters['from']), fn ($q) => $q->where('o.occurred_on', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->where('o.occurred_on', '<=', $filters['to']))
            ->select('m.id', 'm.operation_id', 'm.wallet_id', 'm.amount_signed', 'm.entry_kind', 'o.type', 'o.status', 'o.occurred_on', 'o.note', 'o.created_by_member_id')
            ->orderByDesc('o.occurred_on')->orderByDesc('m.id')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function receipt(Household $household, string $key): ?object
    {
        return DB::table('idempotency_receipts')->where('household_id', $household->id)->where('key', $key)->first();
    }

    public function remember(Household $household, string $key, string $hash, array $response): void
    {
        DB::table('idempotency_receipts')->insert(['household_id' => $household->id, 'key' => $key, 'payload_hash' => $hash, 'endpoint' => 'wallets.store', 'response_status' => 201, 'response_body' => json_encode($response, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function audit(Wallet $wallet, HouseholdMember $actor, string $action, ?array $before): void
    {
        DB::table('audit_logs')->insert(['household_id' => $wallet->household_id, 'actor_member_id' => $actor->id, 'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($wallet->only(['id', 'name', 'type', 'is_archived', 'balance']), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function mismatches(?int $householdId): array
    {
        return DB::select('SELECT w.household_id, w.id AS wallet_id, b.balance AS projected, COALESCE(m.total, 0.00)::text AS ledger
            FROM wallets w LEFT JOIN wallet_balances b ON b.wallet_id = w.id AND b.household_id = w.household_id
            LEFT JOIN (SELECT wallet_id, household_id, SUM(amount_signed) AS total FROM balance_movements GROUP BY wallet_id, household_id) m ON m.wallet_id = w.id AND m.household_id = w.household_id
            WHERE b.balance IS DISTINCT FROM COALESCE(m.total, 0)'.($householdId === null ? '' : ' AND w.household_id = ?').' ORDER BY w.id', $householdId === null ? [] : [$householdId]);
    }
}
