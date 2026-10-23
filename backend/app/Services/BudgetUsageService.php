<?php

namespace App\Services;

use App\Repositories\Contracts\BudgetRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class BudgetUsageService
{
    public function __construct(private BudgetRepository $budgets) {}

    public function report(int $household, string $month, ?array $budget = null): array
    {
        $budget ??= $this->budgets->month($household, $month);
        $expenses = array_column($this->budgets->expenses($household, $month), null, 'category_id');
        $lines = [];
        $limitTotal = BigDecimal::of('0.00');
        $usedTotal = BigDecimal::of('0.00');
        foreach ($budget['lines'] ?? [] as $line) {
            $used = BigDecimal::of($expenses[$line['category_id']]['used_amount'] ?? '0.00');
            $limit = BigDecimal::of($line['limit_amount']);
            $status = $used->isGreaterThan($limit) ? 'exceeded' : ($used->isEqualTo($limit) ? 'reached' : ($used->multipliedBy(100)->isGreaterThanOrEqualTo($limit->multipliedBy(80)) ? 'near_limit' : 'safe'));
            $lines[] = [...$line, 'used_amount' => (string) $used->toScale(2), 'remaining_amount' => (string) $limit->minus($used)->toScale(2), 'percent_used' => (string) $used->multipliedBy(100)->dividedBy($limit, 2, RoundingMode::Down), 'status' => $status];
            $limitTotal = $limitTotal->plus($limit);
            $usedTotal = $usedTotal->plus($used);
            unset($expenses[$line['category_id']]);
        }
        $unbudgeted = BigDecimal::of('0.00');
        foreach ($expenses as $expense) {
            $unbudgeted = $unbudgeted->plus($expense['used_amount']);
        }

        return [...($budget ?? ['id' => null, 'household_id' => $household, 'month_start' => $month]), 'lines' => $lines, 'unbudgeted_expenses' => array_values($expenses), 'totals' => ['limit_amount' => (string) $limitTotal->toScale(2), 'used_amount' => (string) $usedTotal->toScale(2), 'remaining_amount' => (string) $limitTotal->minus($usedTotal)->toScale(2), 'unbudgeted_amount' => (string) $unbudgeted->toScale(2)]];
    }

    public function warnings(int $household, array $operations): array
    {
        $targets = [];
        foreach ($operations as $operation) {
            if (! $operation) {
                continue;
            }
            $category = $operation['type'] === 'expense' ? $operation['category_id'] : ($operation['type'] === 'transfer' && BigDecimal::of($operation['fee_amount'] ?? '0.00')->isPositive() ? $operation['fee_category_id'] : null);
            if ($category !== null) {
                $targets[substr($operation['occurred_on'], 0, 7).'-01'][(int) $category] = true;
            }
        }
        ksort($targets);
        $warnings = [];
        foreach ($targets as $month => $categories) {
            $budget = $this->budgets->month($household, $month);
            if ($budget === null) {
                continue;
            }
            foreach ($this->report($household, $month, $budget)['lines'] as $line) {
                if (! isset($categories[$line['category_id']]) || $line['status'] === 'safe') {
                    continue;
                }
                [$code, $message] = match ($line['status']) {
                    'exceeded' => ['BUDGET_EXCEEDED', 'Budget kategori terlampaui.'],
                    'reached' => ['BUDGET_REACHED', 'Budget kategori sudah terpakai 100%.'],
                    default => ['BUDGET_NEAR_LIMIT', 'Budget kategori sudah terpakai sedikitnya 80%.'],
                };
                $warnings[] = ['code' => $code, 'month_start' => $month, 'category_id' => $line['category_id'], 'percent_used' => $line['percent_used'], 'message' => $message];
            }
        }

        return $warnings;
    }
}
