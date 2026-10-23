<?php

namespace App\Repositories;

use App\Repositories\Contracts\BudgetRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EloquentBudgetRepository implements BudgetRepository
{
    private function serialize(object $row): array
    {
        $row->created_at = Carbon::parse($row->created_at)->utc()->toISOString();
        $row->updated_at = Carbon::parse($row->updated_at)->utc()->toISOString();

        return (array) $row;
    }

    public function list(int $household, array $filters): LengthAwarePaginator
    {
        $query = DB::table('budgets')->where('household_id', $household);
        foreach (['from' => '>=', 'to' => '<='] as $field => $comparison) {
            if (isset($filters[$field])) {
                $query->where('month_start', $comparison, $filters[$field]);
            }
        }

        return $query->orderByDesc('month_start')->paginate($filters['per_page'] ?? 20)->through(fn (object $row): array => $this->serialize($row));
    }

    public function find(int $household, int $id): array
    {
        $row = DB::table('budgets')->where('household_id', $household)->where('id', $id)->first();
        abort_unless($row, 404);
        $lines = DB::table('budget_lines as l')->join('categories as c', function ($join): void {
            $join->on('l.category_id', '=', 'c.id')->on('l.household_id', '=', 'c.household_id');
        })
            ->where('l.household_id', $household)->where('l.budget_id', $id)->orderBy('l.category_id')->get(['l.category_id', 'l.limit_amount', 'c.name as category_name', 'c.is_archived'])->map(fn (object $line): array => (array) $line)->all();

        return [...$this->serialize($row), 'lines' => $lines];
    }

    public function month(int $household, string $month): ?array
    {
        $id = DB::table('budgets')->where('household_id', $household)->where('month_start', $month)->value('id');

        return $id === null ? null : $this->find($household, $id);
    }

    public function save(int $household, int $actor, string $month, array $lines, ?int $id = null, ?int $source = null): array
    {
        if ($id === null) {
            $id = DB::table('budgets')->insertGetId(['household_id' => $household, 'month_start' => $month, 'created_by_member_id' => $actor, 'copied_from_budget_id' => $source, 'created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('budget_lines')->where('household_id', $household)->where('budget_id', $id)->delete();
            DB::table('budgets')->where('household_id', $household)->where('id', $id)->update(['updated_at' => now()]);
        }
        foreach ($lines as $line) {
            DB::table('budget_lines')->insert(['household_id' => $household, 'budget_id' => $id, 'category_id' => $line['category_id'], 'limit_amount' => $line['limit_amount']]);
        }

        return $this->find($household, $id);
    }

    public function expenses(int $household, string $month): array
    {
        $end = Carbon::parse($month)->addMonth()->format('Y-m-d');

        return array_map(fn (object $row): array => (array) $row, DB::select("SELECT e.category_id, c.name AS category_name, c.is_archived, SUM(e.amount)::text AS used_amount FROM (
            SELECT category_id, amount FROM operations WHERE household_id = ? AND status = 'posted' AND type = 'expense' AND occurred_on >= ? AND occurred_on < ?
            UNION ALL
            SELECT t.fee_category_id AS category_id, t.fee_amount AS amount FROM transfer_details t JOIN operations o ON o.id = t.operation_id AND o.household_id = t.household_id
            WHERE o.household_id = ? AND o.status = 'posted' AND o.type = 'transfer' AND t.fee_amount > 0 AND o.occurred_on >= ? AND o.occurred_on < ?
        ) e JOIN categories c ON c.id = e.category_id AND c.household_id = ? GROUP BY e.category_id, c.name, c.is_archived ORDER BY e.category_id", [$household, $month, $end, $household, $month, $end, $household]));
    }
}
