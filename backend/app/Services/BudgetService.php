<?php

namespace App\Services;

use App\Exceptions\FinancialException;
use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\BudgetRepository;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class BudgetService
{
    public function __construct(private HouseholdRepository $households, private BudgetRepository $budgets, private FinanceRepository $finance, private BudgetUsageService $usage) {}

    private function accessible(User $user, int $id, bool $write = false): Household
    {
        $household = $this->households->find($id, lock: $write);
        Gate::forUser($user)->authorize($write ? 'manage-budgets' : 'view-budgets', $household);

        return $household;
    }

    public function list(User $user, int $household, array $filters): LengthAwarePaginator
    {
        $this->accessible($user, $household);

        return $this->budgets->list($household, $filters);
    }

    public function show(User $user, int $household, int $id): array
    {
        $this->accessible($user, $household);
        $budget = $this->budgets->find($household, $id);

        return $this->usage->report($household, $budget['month_start'], $budget);
    }

    public function report(User $user, int $household, string $month): array
    {
        $this->accessible($user, $household);

        return $this->usage->report($household, $month);
    }

    public function save(User $user, int $householdId, array $attributes, ?int $id = null): array
    {
        return DB::transaction(function () use ($user, $householdId, $attributes, $id): array {
            $household = $this->accessible($user, $householdId, true);
            $before = $id === null ? null : $this->budgets->find($householdId, $id);
            $month = $before['month_start'] ?? $attributes['month_start'];
            if ($id === null) {
                $this->ensureAvailable($householdId, $month);
            }
            $existing = array_column($before['lines'] ?? [], 'category_id');
            foreach ($attributes['lines'] as $line) {
                $category = $this->finance->category($householdId, (int) $line['category_id']);
                if ($category->type !== 'expense' || ($category->is_archived && ! in_array((int) $category->id, $existing, true))) {
                    throw new FinancialException('INVALID_CATEGORY_TYPE', 'Budget baru harus memakai kategori pengeluaran aktif.');
                }
            }
            $actor = $this->households->membership($user, $household)->id;
            $budget = $this->budgets->save($householdId, $actor, $month, $attributes['lines'], $id);
            $this->finance->audit($householdId, $actor, $id === null ? 'budget.created' : 'budget.updated', $before, $budget);

            return $this->usage->report($householdId, $month, $budget);
        }, attempts: 3);
    }

    public function copy(User $user, int $householdId, array $attributes): array
    {
        return DB::transaction(function () use ($user, $householdId, $attributes): array {
            $household = $this->accessible($user, $householdId, true);
            $this->ensureAvailable($householdId, $attributes['target_month']);
            $source = $this->budgets->month($householdId, $attributes['source_month']);
            abort_unless($source, 404);
            $actor = $this->households->membership($user, $household)->id;
            $budget = $this->budgets->save($householdId, $actor, $attributes['target_month'], $source['lines'], source: $source['id']);
            $this->finance->audit($householdId, $actor, 'budget.copied', null, $budget);

            return [...$this->usage->report($householdId, $attributes['target_month'], $budget), 'lines_copied' => count($source['lines'])];
        }, attempts: 3);
    }

    private function ensureAvailable(int $household, string $month): void
    {
        if ($this->budgets->month($household, $month)) {
            throw new FinancialException('BUDGET_ALREADY_EXISTS', 'Budget untuk bulan ini sudah ada. Buka budget tersebut untuk mengubahnya.', 409);
        }
    }
}
