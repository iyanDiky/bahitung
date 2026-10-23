<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\ReportRepository;
use App\Repositories\Contracts\WalletRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class ReportService
{
    public function __construct(private HouseholdRepository $households, private ReportRepository $reports, private WalletRepository $wallets) {}

    public function cashflow(User $user, int $householdId, array $filters): array
    {
        $household = $this->households->find($householdId);
        Gate::forUser($user)->authorize('view-reports', $household);
        $wallet = isset($filters['wallet_id']) ? (int) $filters['wallet_id'] : null;
        if ($wallet !== null) {
            $this->wallets->find($household, $wallet);
        }
        $from = $filters['from'];
        $to = $filters['to'];
        $daily = [];
        $monthly = [];
        $categories = ['income' => [], 'expense' => []];
        $totals = $this->emptyTotals();
        for ($day = CarbonImmutable::parse($from); $day->format('Y-m-d') <= $to && $day->year <= 9999; $day = $day->addDay()) {
            $date = $day->format('Y-m-d');
            $month = $day->format('Y-m');
            $daily[$date] = ['date' => $date, ...$this->emptyTotals()];
            $monthly[$month] ??= ['month' => $month, ...$this->emptyTotals()];
        }
        foreach ($this->reports->cashflow($householdId, $from, $to, $wallet) as $row) {
            $date = $row['occurred_on'];
            $month = substr($date, 0, 7);
            $this->accumulate($totals, $row);
            $this->accumulate($daily[$date], $row);
            $this->accumulate($monthly[$month], $row);
            $type = $row['type'];
            $id = $row['category_id'];
            $categories[$type][$id] ??= ['category_id' => $id, 'category_name' => $row['category_name'], 'is_archived' => $row['is_archived'], 'amount' => '0.00', 'fee_amount' => '0.00', 'entry_count' => 0];
            $category = &$categories[$type][$id];
            $category['amount'] = $this->plus($category['amount'], $row['amount']);
            $category['fee_amount'] = $this->plus($category['fee_amount'], $row['fee_amount']);
            $category['entry_count'] += $row['entry_count'];
            unset($category);
        }
        foreach (['income', 'expense'] as $type) {
            $total = BigDecimal::of($totals[$type.'_amount']);
            foreach ($categories[$type] as &$category) {
                $category['percent'] = $total->isZero() ? '0.00' : (string) BigDecimal::of($category['amount'])->multipliedBy(100)->dividedBy($total, 2, RoundingMode::Down);
            }
            unset($category);
            usort($categories[$type], fn (array $a, array $b): int => BigDecimal::of($b['amount'])->compareTo($a['amount']) ?: $a['category_id'] <=> $b['category_id']);
        }
        $income = BigDecimal::of($totals['income_amount']);
        $totals['net_percent_of_income'] = $income->isZero() ? null : (string) BigDecimal::of($totals['net_amount'])->multipliedBy(100)->dividedBy($income, 2, RoundingMode::Down);

        return ['household_id' => $householdId, 'from' => $from, 'to' => $to, 'wallet_id' => $wallet, 'timezone' => $household->timezone, 'totals' => $totals, 'daily' => array_values($daily), 'monthly' => array_values($monthly), 'categories' => $categories];
    }

    /** @return array{income_amount:string,expense_amount:string,net_amount:string,fee_amount:string,income_count:int,expense_count:int} */
    private function emptyTotals(): array
    {
        return ['income_amount' => '0.00', 'expense_amount' => '0.00', 'net_amount' => '0.00', 'fee_amount' => '0.00', 'income_count' => 0, 'expense_count' => 0];
    }

    private function accumulate(array &$totals, array $row): void
    {
        $field = $row['type'].'_amount';
        $totals[$field] = $this->plus($totals[$field], $row['amount']);
        $totals[$row['type'].'_count'] += $row['entry_count'];
        $totals['fee_amount'] = $this->plus($totals['fee_amount'], $row['fee_amount']);
        $totals['net_amount'] = (string) BigDecimal::of($totals['income_amount'])->minus($totals['expense_amount'])->toScale(2);
    }

    private function plus(string $left, string $right): string
    {
        return (string) BigDecimal::of($left)->plus($right)->toScale(2);
    }
}
