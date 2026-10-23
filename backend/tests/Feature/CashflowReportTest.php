<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\CorrectionService;
use App\Services\GoalService;
use App\Services\OperationPostingService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CashflowReportTest extends TestCase
{
    use DatabaseTransactions;

    private Household $household;

    private User $owner;

    private int $wallet;

    private int $destination;

    private int $income;

    private int $expense;

    private int $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->household = Household::factory()->create();
        $this->owner = User::findOrFail($this->household->created_by_user_id);
        $this->wallet = $this->wallet('1000000.00');
        $this->destination = $this->wallet('0.00');
        $this->income = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Gaji', 'type' => 'income'])->id;
        $this->expense = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Belanja', 'type' => 'expense'])->id;
        $this->fee = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Admin', 'type' => 'expense'])->id;
        $this->authenticate($this->owner);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function wallet(string $amount): int
    {
        return app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Dompet', 'type' => 'bank', 'opening_amount' => $amount, 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
    }

    private function report(array $filters = []): TestResponse
    {
        return $this->getJson('/api/v1/households/'.$this->household->id.'/reports/cashflow?'.http_build_query(array_replace(['from' => '2026-01-01', 'to' => '2026-01-31'], $filters)));
    }

    private function postOperation(string $type, string $amount, string $date = '2026-01-02', ?int $wallet = null): int
    {
        return app(OperationPostingService::class)->create($this->owner, $this->household->id, ['type' => $type, 'amount' => $amount, 'wallet_id' => $wallet ?? $this->wallet, 'category_id' => $type === 'income' ? $this->income : $this->expense, 'occurred_on' => $date, 'idempotency_key' => (string) Str::uuid()])['data']['id'];
    }

    private function transfer(string $fee = '2500.00'): int
    {
        return app(OperationPostingService::class)->create($this->owner, $this->household->id, ['type' => 'transfer', 'amount' => '100000.00', 'source_wallet_id' => $this->wallet, 'destination_wallet_id' => $this->destination, 'fee_amount' => $fee, 'fee_category_id' => $this->fee, 'occurred_on' => '2026-01-03', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
    }

    private function reverse(int $id, ?array $replacement = null): void
    {
        app(CorrectionService::class)->apply($this->owner, $this->household->id, $id, ['reason' => 'Perbaikan laporan', 'idempotency_key' => (string) Str::uuid(), ...($replacement ? ['replacement' => $replacement] : [])], $replacement !== null);
    }

    public function test_empty_report_has_zero_series_and_undefined_ratio_without_income(): void
    {
        $this->report()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.totals.income_amount', '0.00')->assertJsonPath('data.totals.net_percent_of_income', null)->assertJsonCount(31, 'data.daily')->assertJsonCount(1, 'data.monthly')->assertJsonPath('data.daily.30.date', '2026-01-31')->assertJsonPath('data.categories', ['income' => [], 'expense' => []]);
    }

    public function test_fee_is_included_once_and_principal_opening_and_goals_are_excluded(): void
    {
        $this->postOperation('income', '200000.00');
        $this->postOperation('expense', '50000.00');
        $this->transfer();
        $this->transfer('0.00');
        $goal = app(GoalService::class)->save($this->owner, $this->household->id, ['name' => 'Sekolah', 'target_amount' => '100000.00'])['id'];
        app(OperationPostingService::class)->createGoal($this->owner, $this->household->id, $goal, ['wallet_id' => $this->wallet, 'amount' => '100000.00', 'occurred_on' => '2026-01-04', 'idempotency_key' => (string) Str::uuid()], false);
        app(OperationPostingService::class)->createGoal($this->owner, $this->household->id, $goal, ['destination_wallet_id' => $this->wallet, 'amount' => '25000.00', 'occurred_on' => '2026-01-05', 'idempotency_key' => (string) Str::uuid()], true);
        $this->report()->assertOk()->assertJsonPath('data.totals.income_amount', '200000.00')->assertJsonPath('data.totals.expense_amount', '52500.00')->assertJsonPath('data.totals.net_amount', '147500.00')->assertJsonPath('data.totals.fee_amount', '2500.00')->assertJsonPath('data.totals.income_count', 1)->assertJsonPath('data.totals.expense_count', 2)->assertJsonPath('data.totals.net_percent_of_income', '73.75')->assertJsonPath('data.daily.2.expense_amount', '2500.00')->assertJsonPath('data.categories.expense.0.category_id', $this->expense)->assertJsonPath('data.categories.expense.1.fee_amount', '2500.00')->assertJsonPath('data.categories.expense.1.percent', '4.76');
    }

    public function test_wallet_filter_charges_only_source_wallet_for_transfer_fee(): void
    {
        $this->transfer();
        $this->postOperation('expense', '10000.00', '2026-01-04', $this->destination);
        $this->report(['wallet_id' => $this->wallet])->assertOk()->assertJsonPath('data.totals.expense_amount', '2500.00');
        $this->report(['wallet_id' => $this->destination])->assertOk()->assertJsonPath('data.totals.expense_amount', '10000.00')->assertJsonPath('data.totals.fee_amount', '0.00');
        $this->report()->assertJsonPath('data.totals.expense_amount', '12500.00');
    }

    public function test_date_boundaries_and_monthly_series_include_empty_days(): void
    {
        $this->postOperation('expense', '1.00', '2026-01-30');
        $this->postOperation('expense', '2.00', '2026-01-31');
        $this->postOperation('income', '3.00', '2026-02-01');
        $this->postOperation('expense', '4.00', '2026-02-03');
        $this->report(['from' => '2026-01-31', 'to' => '2026-02-02'])->assertOk()->assertJsonCount(3, 'data.daily')->assertJsonCount(2, 'data.monthly')->assertJsonPath('data.totals.expense_amount', '2.00')->assertJsonPath('data.totals.income_amount', '3.00')->assertJsonPath('data.monthly.0.net_amount', '-2.00')->assertJsonPath('data.monthly.1.net_amount', '3.00')->assertJsonPath('data.daily.2.net_amount', '0.00');
    }

    public function test_correction_updates_old_new_month_wallet_and_category_and_void_removes_effect(): void
    {
        $this->transfer('0.00');
        $id = $this->postOperation('expense', '20000.00');
        $this->reverse($id, ['type' => 'expense', 'amount' => '30000.00', 'wallet_id' => $this->destination, 'category_id' => $this->fee, 'occurred_on' => '2026-02-01']);
        $this->report()->assertJsonPath('data.totals.expense_amount', '0.00');
        $this->report(['from' => '2026-02-01', 'to' => '2026-02-28', 'wallet_id' => $this->wallet])->assertJsonPath('data.totals.expense_amount', '0.00');
        $this->report(['from' => '2026-02-01', 'to' => '2026-02-28', 'wallet_id' => $this->destination])->assertJsonPath('data.totals.expense_amount', '30000.00')->assertJsonPath('data.categories.expense.0.category_id', $this->fee)->assertJsonPath('data.totals.fee_amount', '0.00');
        $replacement = DB::table('operations')->where('correction_of_id', $id)->value('id');
        $this->reverse($replacement);
        $this->report(['from' => '2026-01-01', 'to' => '2026-02-28'])->assertJsonPath('data.totals.expense_amount', '0.00');
    }

    public function test_transfer_correction_and_void_do_not_double_count_fees(): void
    {
        $id = $this->transfer();
        $this->reverse($id, ['type' => 'transfer', 'source_wallet_id' => $this->wallet, 'destination_wallet_id' => $this->destination, 'amount' => '50000.00', 'fee_amount' => '1500.00', 'fee_category_id' => $this->fee, 'occurred_on' => '2026-01-03']);
        $this->report()->assertJsonPath('data.totals.expense_amount', '1500.00')->assertJsonPath('data.totals.fee_amount', '1500.00')->assertJsonPath('data.totals.expense_count', 1);
        $this->reverse(DB::table('operations')->where('correction_of_id', $id)->value('id'));
        $this->report()->assertJsonPath('data.totals.expense_amount', '0.00')->assertJsonCount(0, 'data.categories.expense');
    }

    public function test_archived_categories_and_wallets_remain_in_reports(): void
    {
        $this->postOperation('expense', '50.00');
        DB::table('wallets')->where('id', $this->wallet)->update(['is_archived' => true]);
        DB::table('categories')->where('id', $this->expense)->update(['is_archived' => true]);
        $this->report(['wallet_id' => $this->wallet])->assertOk()->assertJsonPath('data.totals.expense_amount', '50.00')->assertJsonPath('data.categories.expense.0.is_archived', true);
    }

    public function test_sums_remain_exact_beyond_javascript_and_single_amount_limits(): void
    {
        $a = $this->wallet('0.00');
        $b = $this->wallet('0.00');
        $this->postOperation('income', '99999999999999999.99', '2026-01-02', $a);
        $this->postOperation('income', '99999999999999999.99', '2026-01-02', $b);
        $this->report()->assertOk()->assertJsonPath('data.totals.income_amount', '199999999999999999.98')->assertJsonPath('data.categories.income.0.amount', '199999999999999999.98')->assertJsonPath('data.daily.1.income_amount', '199999999999999999.98');
    }

    public function test_negative_net_and_zero_income_ratio_are_explicit(): void
    {
        $this->postOperation('expense', '200.00');
        $this->report()->assertJsonPath('data.totals.net_amount', '-200.00')->assertJsonPath('data.totals.net_percent_of_income', null);
        $this->postOperation('income', '100.00');
        $this->report()->assertJsonPath('data.totals.net_percent_of_income', '-100.00');
    }

    #[TestWith(['owner'])] #[TestWith(['admin'])] #[TestWith(['member'])] #[TestWith(['viewer'])]
    public function test_all_active_roles_can_read(string $role): void
    {
        if ($role !== 'owner') {
            $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => $role]);
            $this->authenticate(User::findOrFail($member->user_id));
        }
        $this->report()->assertOk();
    }

    public function test_cross_household_wallet_and_removed_member_are_rejected(): void
    {
        $other = Household::factory()->create();
        $user = User::findOrFail($other->created_by_user_id);
        $wallet = app(WalletService::class)->create($user, $other->id, ['name' => 'Other', 'type' => 'cash', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->report(['wallet_id' => $wallet])->assertNotFound();
        $this->getJson('/api/v1/households/'.$other->id.'/reports/cashflow?from=2026-01-01&to=2026-01-31')->assertNotFound();
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'viewer']);
        $this->authenticate(User::findOrFail($member->user_id));
        $member->update(['status' => 'removed']);
        $this->report()->assertNotFound();
    }

    public function test_archived_household_and_guest_cannot_read(): void
    {
        $this->household->forceFill(['archived_at' => now()])->save();
        $this->report()->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '');
        $this->report()->assertUnauthorized();
    }

    #[TestWith(['2026-02-30', '2026-03-01'])] #[TestWith(['2026-02-01', '2026-01-01'])] #[TestWith(['2025-01-01', '2026-01-02'])] #[TestWith(['0000-01-01', '0001-01-01'])] #[TestWith(['bad', 'bad'])]
    public function test_invalid_dates_and_excessive_ranges_are_rejected(string $from, string $to): void
    {
        $this->report(compact('from', 'to'))->assertUnprocessable();
    }

    public function test_required_filters_and_valid_leap_day_and_extreme_dates(): void
    {
        $this->getJson('/api/v1/households/'.$this->household->id.'/reports/cashflow')->assertUnprocessable();
        $this->report(['wallet_id' => 0])->assertUnprocessable();
        $this->report(['from' => '2024-01-01', 'to' => '2024-12-31'])->assertOk()->assertJsonCount(366, 'data.daily');
        $this->report(['from' => '0001-01-01', 'to' => '0001-01-01'])->assertOk()->assertJsonCount(1, 'data.daily');
        $this->report(['from' => '9999-12-31', 'to' => '9999-12-31'])->assertOk()->assertJsonCount(1, 'data.daily');
    }

    public function test_other_household_transactions_never_enter_totals_or_categories(): void
    {
        $this->postOperation('expense', '20.00');
        $other = Household::factory()->create();
        $user = User::findOrFail($other->created_by_user_id);
        $wallet = app(WalletService::class)->create($user, $other->id, ['name' => 'Other', 'type' => 'bank', 'opening_amount' => '100000.00', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $category = app(CategoryService::class)->save($user, $other->id, ['name' => 'Belanja', 'type' => 'expense'])->id;
        app(OperationPostingService::class)->create($user, $other->id, ['type' => 'expense', 'amount' => '99999.00', 'wallet_id' => $wallet, 'category_id' => $category, 'occurred_on' => '2026-01-02', 'idempotency_key' => (string) Str::uuid()]);
        $this->report()->assertOk()->assertJsonPath('data.totals.expense_amount', '20.00')->assertJsonCount(1, 'data.categories.expense')->assertJsonPath('data.categories.expense.0.category_id', $this->expense);
    }

    public function test_void_income_is_excluded_without_double_subtraction(): void
    {
        $id = $this->postOperation('income', '100.00');
        $this->postOperation('income', '25.50');
        $this->reverse($id);
        $this->report()->assertOk()->assertJsonPath('data.totals.income_amount', '25.50')->assertJsonPath('data.totals.income_count', 1)->assertJsonPath('data.categories.income.0.amount', '25.50');
    }
}
