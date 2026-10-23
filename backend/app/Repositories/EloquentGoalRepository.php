<?php

namespace App\Repositories;

use App\Repositories\Contracts\GoalRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentGoalRepository implements GoalRepository
{
    private function query(int $household): Builder
    {
        return DB::table('goals as g')->join('goal_balances as b', fn ($join) => $join->on('b.goal_id', '=', 'g.id')->on('b.household_id', '=', 'g.household_id'))->where('g.household_id', $household)->select('g.*', 'b.balance');
    }

    private function present(object $row): array
    {
        $balance = BigDecimal::of($row->balance);
        $target = BigDecimal::of($row->target_amount);

        return [...(array) $row, 'created_at' => Carbon::parse($row->created_at)->utc()->toISOString(), 'updated_at' => Carbon::parse($row->updated_at)->utc()->toISOString(), 'remaining_amount' => (string) BigDecimal::max('0.00', $target->minus($balance))->toScale(2), 'percent_saved' => (string) $balance->multipliedBy(100)->dividedBy($target, 2, RoundingMode::Down), 'is_reached' => $balance->isGreaterThanOrEqualTo($target)];
    }

    public function list(int $household, array $filters): LengthAwarePaginator
    {
        return $this->query($household)->when(! empty($filters['q']), fn ($q) => $q->where('g.name', 'ilike', '%'.$filters['q'].'%'))->when(($filters['status'] ?? 'all') !== 'all', fn ($q) => $q->where('g.is_archived', $filters['status'] === 'archived'))->orderBy('g.id')->paginate($filters['per_page'] ?? 20)->through(fn ($row) => $this->present($row));
    }

    public function find(int $household, int $id): array
    {
        $row = $this->query($household)->where('g.id', $id)->first();
        abort_unless($row, 404);

        return $this->present($row);
    }

    public function save(int $household, int $actor, array $attributes, ?int $id = null): array
    {
        if ($id === null) {
            $id = DB::table('goals')->insertGetId([...$attributes, 'household_id' => $household, 'created_by_member_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('goal_balances')->insert(['goal_id' => $id, 'household_id' => $household, 'balance' => '0.00', 'updated_at' => now()]);
        } else {
            DB::table('goals')->where('household_id', $household)->where('id', $id)->update([...$attributes, 'updated_at' => now()]);
        }

        return $this->find($household, $id);
    }

    public function lock(int $household, array $ids): array
    {
        sort($ids, SORT_NUMERIC);
        $result = [];
        foreach ($ids as $id) {
            $goal = $this->find($household, $id);
            $projection = DB::table('goal_balances')->where('household_id', $household)->where('goal_id', $id)->lockForUpdate()->first();
            abort_unless($projection, 409);
            $result[$id] = ['balance' => $projection->balance, 'is_archived' => $goal['is_archived']];
        }

        return $result;
    }

    public function updateBalances(int $household, array $balances): void
    {
        foreach ($balances as $id => $balance) {
            DB::table('goal_balances')->where('household_id', $household)->where('goal_id', $id)->update(['balance' => $balance, 'updated_at' => now()]);
        }
    }

    public function movements(int $household, int $id, array $filters): LengthAwarePaginator
    {
        return DB::table('balance_movements as m')->join('operations as o', fn ($join) => $join->on('o.id', '=', 'm.operation_id')->on('o.household_id', '=', 'm.household_id'))->where('m.household_id', $household)->where('m.goal_id', $id)
            ->when(isset($filters['from']), fn ($q) => $q->where('o.occurred_on', '>=', $filters['from']))->when(isset($filters['to']), fn ($q) => $q->where('o.occurred_on', '<=', $filters['to']))
            ->select('m.id', 'm.goal_id', 'm.operation_id', 'm.amount_signed', 'm.entry_kind', 'm.reversal_of_id', 'o.type', 'o.status', 'o.occurred_on', 'o.note', 'o.created_by_member_id')->orderByDesc('o.occurred_on')->orderByDesc('m.id')->paginate($filters['per_page'] ?? 20);
    }

    public function mismatches(?int $household): array
    {
        return DB::select('SELECT g.household_id, g.id AS goal_id, b.balance AS projected, COALESCE(m.total, 0.00)::text AS ledger FROM goals g LEFT JOIN goal_balances b ON b.goal_id = g.id AND b.household_id = g.household_id LEFT JOIN (SELECT goal_id, household_id, SUM(amount_signed) AS total FROM balance_movements GROUP BY goal_id, household_id) m ON m.goal_id = g.id AND m.household_id = g.household_id WHERE b.balance IS DISTINCT FROM COALESCE(m.total, 0)'.($household === null ? '' : ' AND g.household_id = ?').' ORDER BY g.id', $household === null ? [] : [$household]);
    }
}
