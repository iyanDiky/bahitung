<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use DatabaseTransactions;

    private Household $household;

    private User $owner;

    private int $wallet;

    private int $destination;

    private int $expense;

    private int $income;

    protected function setUp(): void
    {
        parent::setUp();
        $this->household = Household::factory()->create();
        $this->owner = User::findOrFail($this->household->created_by_user_id);
        $this->wallet = $this->wallet('1000000.00');
        $this->destination = $this->wallet('0.00');
        $this->expense = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Belanja', 'type' => 'expense'])->id;
        $this->income = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Gaji', 'type' => 'income'])->id;
        $this->authenticate($this->owner);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function wallet(string $amount, string $date = '2026-01-01'): int
    {
        return app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Dompet', 'type' => 'bank', 'opening_amount' => $amount, 'opening_on' => $date, 'idempotency_key' => (string) Str::uuid()])['data']['id'];
    }

    private function base(string $resource = 'operations'): string
    {
        return '/api/v1/households/'.$this->household->id.'/'.$resource;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['type' => 'expense', 'wallet_id' => $this->wallet, 'category_id' => $this->expense, 'amount' => '80000.00', 'occurred_on' => '2026-01-02'], $overrides);
    }

    private function transfer(array $overrides = []): array
    {
        return array_replace(['type' => 'transfer', 'source_wallet_id' => $this->wallet, 'destination_wallet_id' => $this->destination, 'amount' => '500000.00', 'fee_amount' => '2500.00', 'fee_category_id' => $this->expense, 'occurred_on' => '2026-01-02'], $overrides);
    }

    private function postOperation(array $payload, ?string $key = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson($this->base(), $payload);
    }

    private function operation(array $overrides = []): int
    {
        return $this->postOperation($this->payload($overrides))->assertCreated()->json('data.id');
    }

    private function change(int $id, ?array $replacement = null, ?string $key = null, string $reason = 'Salah catat'): TestResponse
    {
        $body = ['reason' => $reason];
        if ($replacement !== null) {
            $body['replacement'] = $replacement;
        }

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson($this->base().'/'.$id.($replacement === null ? '/void' : '/correct'), $body);
    }

    private function budget(string $limit = '100000.00', string $month = '2026-01-01'): int
    {
        return $this->postJson($this->base('budgets'), ['month_start' => $month, 'lines' => [['category_id' => $this->expense, 'limit_amount' => $limit]]])->assertCreated()->json('data.id');
    }

    private function usage(string $month = '2026-01-01'): TestResponse
    {
        return $this->getJson($this->base('reports/budget-usage').'?month_start='.$month)->assertOk();
    }

    public function test_budget_crud_precise_limits_and_explicit_replacement_lines(): void
    {
        $id = $this->budget('9007199254740993.25');
        $this->getJson($this->base('budgets').'/'.$id)->assertOk()->assertJsonPath('data.lines.0.limit_amount', '9007199254740993.25')->assertJsonPath('data.lines.0.used_amount', '0.00');
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '200000.25']]])->assertOk()->assertJsonPath('data.totals.limit_amount', '200000.25');
        $this->assertDatabaseCount('budget_lines', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'budget.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'budget.updated']);
        $this->postJson($this->base('budgets'), ['month_start' => '2026-01-01', 'lines' => [['category_id' => $this->expense, 'limit_amount' => '1.00']]])->assertConflict()->assertJsonPath('error.code', 'BUDGET_ALREADY_EXISTS');
    }

    #[TestWith(['79999.99', null, 'safe', '79.99'])]
    #[TestWith(['80000.00', 'BUDGET_NEAR_LIMIT', 'near_limit', '80.00'])]
    #[TestWith(['99999.99', 'BUDGET_NEAR_LIMIT', 'near_limit', '99.99'])]
    #[TestWith(['100000.00', 'BUDGET_REACHED', 'reached', '100.00'])]
    #[TestWith(['105000.00', 'BUDGET_EXCEEDED', 'exceeded', '105.00'])]
    public function test_threshold_warnings_do_not_block_posting(string $amount, ?string $code, string $status, string $percent): void
    {
        $this->budget();
        $response = $this->postOperation($this->payload(['amount' => $amount]))->assertCreated();
        if ($code === null) {
            $response->assertJsonCount(0, 'warnings');
        } else {
            $response->assertJsonPath('warnings.0.code', $code)->assertJsonPath('warnings.0.percent_used', $percent)->assertJsonPath('warnings.0.month_start', '2026-01-01');
        }
        $this->usage()->assertJsonPath('data.lines.0.status', $status)->assertJsonPath('data.lines.0.used_amount', $amount)->assertJsonPath('data.lines.0.percent_used', $percent);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_fee_only_is_expense_and_transfer_without_fee_and_income_are_excluded(): void
    {
        $this->budget('2500.00');
        $this->postOperation($this->transfer())->assertCreated()->assertJsonPath('warnings.0.code', 'BUDGET_REACHED');
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income]))->assertCreated()->assertJsonCount(0, 'warnings');
        $this->postOperation($this->transfer(['amount' => '100.00', 'fee_amount' => '0.00', 'fee_category_id' => null]))->assertCreated()->assertJsonCount(0, 'warnings');
        $this->usage()->assertJsonPath('data.totals.used_amount', '2500.00');
    }

    public function test_unbudgeted_expenses_allowed_and_reported_separately(): void
    {
        $this->postOperation($this->payload())->assertCreated()->assertJsonCount(0, 'warnings');
        $this->usage()->assertJsonPath('data.id', null)->assertJsonCount(0, 'data.lines')->assertJsonPath('data.totals.unbudgeted_amount', '80000.00');
        $id = $this->budget();
        $this->getJson($this->base('budgets').'/'.$id)->assertJsonPath('data.lines.0.used_amount', '80000.00')->assertJsonPath('data.totals.unbudgeted_amount', '0.00');
    }

    public function test_corrections_recalculate_both_months_and_void_removes_usage(): void
    {
        $this->budget();
        $this->budget('50000.00', '2026-02-01');
        $id = $this->operation(['amount' => '100000.00']);
        $response = $this->change($id, $this->payload(['amount' => '60000.00', 'occurred_on' => '2026-02-10']))->assertCreated()->assertJsonCount(1, 'warnings')->assertJsonPath('warnings.0.code', 'BUDGET_EXCEEDED')->assertJsonPath('warnings.0.month_start', '2026-02-01');
        $this->usage()->assertJsonPath('data.lines.0.used_amount', '0.00');
        $this->usage('2026-02-01')->assertJsonPath('data.lines.0.used_amount', '60000.00');
        $this->change($response->json('data.replacement_operation_id'))->assertOk()->assertJsonCount(0, 'warnings');
        $this->usage('2026-02-01')->assertJsonPath('data.lines.0.used_amount', '0.00');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_transfer_fee_reversal_and_category_change_refresh_usage(): void
    {
        $other = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Admin', 'type' => 'expense'])->id;
        $this->budget('2000.00');
        $id = $this->postOperation($this->transfer())->assertCreated()->json('data.id');
        $replacement = $this->change($id, $this->transfer(['fee_category_id' => $other]))->assertCreated()->json('data.replacement_operation_id');
        $this->usage()->assertJsonPath('data.lines.0.used_amount', '0.00')->assertJsonPath('data.totals.unbudgeted_amount', '2500.00');
        $this->change($replacement)->assertOk();
        $this->usage()->assertJsonPath('data.totals.unbudgeted_amount', '0.00');
    }

    public function test_replay_preserves_original_warning_after_limits_changed(): void
    {
        $id = $this->budget();
        $key = (string) Str::uuid();
        $first = $this->postOperation($this->payload(), $key)->assertCreated()->json();
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '1000.00']]])->assertOk();
        $this->assertEquals($first, $this->postOperation($this->payload(), $key)->assertCreated()->json());
        $this->usage()->assertJsonPath('data.lines.0.status', 'exceeded');
        $this->assertDatabaseCount('operations', 2);
    }

    public function test_copy_is_independent_and_does_not_carry_spending_or_balance(): void
    {
        $source = $this->budget();
        $this->operation();
        $body = ['source_month' => '2026-01-01', 'target_month' => '2026-02-01'];
        $target = $this->postJson($this->base('budgets/copy'), $body)->assertCreated()->assertJsonPath('data.copied_from_budget_id', $source)->assertJsonPath('data.lines_copied', 1)->assertJsonPath('data.lines.0.used_amount', '0.00')->json('data.id');
        $this->patchJson($this->base('budgets').'/'.$target, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '150000.00']]])->assertOk();
        $this->getJson($this->base('budgets').'/'.$source)->assertOk()->assertJsonPath('data.lines.0.limit_amount', '100000.00')->assertJsonPath('data.lines.0.used_amount', '80000.00');
        $this->postJson($this->base('budgets/copy'), $body)->assertConflict()->assertJsonPath('error.code', 'BUDGET_ALREADY_EXISTS');
        $this->assertDatabaseCount('budgets', 2);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '920000.00']);
    }

    public function test_archived_category_keeps_usage_and_copy_but_cannot_be_newly_added(): void
    {
        $id = $this->budget();
        $this->operation();
        $this->patchJson($this->base('categories').'/'.$this->expense, ['is_archived' => true])->assertOk();
        $this->usage()->assertJsonPath('data.lines.0.used_amount', '80000.00')->assertJsonPath('data.lines.0.is_archived', true);
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-01-01', 'target_month' => '2026-02-01'])->assertCreated()->assertJsonPath('data.lines.0.is_archived', true);
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '150000.00']]])->assertOk();
        $this->postJson($this->base('budgets'), ['month_start' => '2026-03-01', 'lines' => [['category_id' => $this->expense, 'limit_amount' => '100.00']]])->assertUnprocessable();
    }

    #[TestWith(['owner', 201])]
    #[TestWith(['admin', 201])]
    #[TestWith(['member', 403])]
    #[TestWith(['viewer', 403])]
    public function test_budget_role_matrix(string $role, int $status): void
    {
        $user = $role === 'owner' ? $this->owner : HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => $role])->user;
        $this->authenticate($user);
        $this->getJson($this->base('budgets'))->assertOk();
        $this->usage();
        $this->postJson($this->base('budgets'), ['month_start' => '2026-01-01', 'lines' => [['category_id' => $this->expense, 'limit_amount' => '100.00']]])->assertStatus($status);
    }

    public function test_tenant_isolation_income_category_and_inactive_membership(): void
    {
        $id = $this->budget();
        $other = Household::factory()->create();
        $owner = User::findOrFail($other->created_by_user_id);
        $category = app(CategoryService::class)->save($owner, $other->id, ['name' => 'Foreign', 'type' => 'expense'])->id;
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $category, 'limit_amount' => '100.00']]])->assertNotFound();
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->income, 'limit_amount' => '100.00']]])->assertUnprocessable();
        $this->authenticate($owner);
        $this->getJson($this->base('budgets').'/'.$id)->assertNotFound();
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-01-01', 'target_month' => '2026-02-01'])->assertNotFound();
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'admin', 'status' => 'removed']);
        $this->authenticate($member->user);
        $this->getJson($this->base('budgets'))->assertNotFound();
    }

    #[TestWith(['0.00'])]
    #[TestWith(['-1.00'])]
    #[TestWith(['01.00'])]
    #[TestWith(['1.001'])]
    #[TestWith(['1e3'])]
    #[TestWith([100])]
    #[TestWith(['100000000000000000.00'])]
    public function test_invalid_limit_is_rejected(mixed $limit): void
    {
        $this->postJson($this->base('budgets'), ['month_start' => '2026-01-01', 'lines' => [['category_id' => $this->expense, 'limit_amount' => $limit]]])->assertUnprocessable();
        $this->assertDatabaseCount('budgets', 0);
    }

    #[TestWith(['2026-01-02'])]
    #[TestWith(['0000-01-01'])]
    #[TestWith(['2026-13-01'])]
    public function test_month_must_be_valid_first_day(string $month): void
    {
        $this->postJson($this->base('budgets'), ['month_start' => $month, 'lines' => [['category_id' => $this->expense, 'limit_amount' => '100.00']]])->assertUnprocessable();
    }

    public function test_validation_bounds_unique_lines_full_update_and_copy_direction(): void
    {
        $id = $this->budget();
        $line = ['category_id' => $this->expense, 'limit_amount' => '100.00'];
        $this->postJson($this->base('budgets'), ['month_start' => '2026-02-01', 'lines' => [$line, $line]])->assertUnprocessable();
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => []])->assertUnprocessable();
        $this->patchJson($this->base('budgets').'/'.$id, ['month_start' => '2026-02-01', 'lines' => [$line]])->assertUnprocessable();
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-02-01', 'target_month' => '2026-01-01'])->assertUnprocessable();
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-03-01', 'target_month' => '2026-04-01'])->assertNotFound();
        $this->getJson($this->base('budgets').'?per_page=101')->assertUnprocessable();
        $this->getJson($this->base('budgets').'?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
        $this->getJson($this->base('budgets').'?from=2026-01-01&to=2026-01-01')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_audit_failure_rolls_back_copy_and_update(): void
    {
        $id = $this->budget();
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_budget_audit CHECK (action NOT IN ('budget.copied', 'budget.updated'))");
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-01-01', 'target_month' => '2026-02-01'])->assertStatus(500);
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '50.00']]])->assertStatus(500);
        $this->assertDatabaseCount('budgets', 1);
        $this->assertDatabaseCount('budget_lines', 1);
        $this->usage()->assertJsonPath('data.lines.0.limit_amount', '100000.00');
    }

    public function test_report_month_boundaries_and_all_wallets_members_are_shared(): void
    {
        $this->budget();
        $this->postOperation($this->transfer(['amount' => '100000.00', 'fee_amount' => '0.00', 'occurred_on' => '2026-01-01']))->assertCreated();
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate($member->user);
        $this->postOperation($this->payload(['wallet_id' => $this->destination, 'amount' => '40000.00', 'occurred_on' => '2026-01-31']))->assertCreated();
        $this->postOperation($this->payload(['amount' => '40000.00', 'occurred_on' => '2026-01-01']))->assertCreated()->assertJsonPath('warnings.0.code', 'BUDGET_NEAR_LIMIT');
        $this->postOperation($this->payload(['amount' => '1.00', 'occurred_on' => '2026-02-01']))->assertCreated()->assertJsonCount(0, 'warnings');
        $this->usage()->assertJsonPath('data.lines.0.used_amount', '80000.00');
    }

    #[TestWith(['GET', 'budgets'])]
    #[TestWith(['POST', 'budgets'])]
    #[TestWith(['GET', 'budgets/1'])]
    #[TestWith(['PATCH', 'budgets/1'])]
    #[TestWith(['POST', 'budgets/copy'])]
    #[TestWith(['GET', 'reports/budget-usage'])]
    public function test_budget_endpoints_require_auth(string $method, string $resource): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->json($method, $this->base($resource))->assertUnauthorized();
    }

    public function test_database_enforces_budget_tenant_and_positive_limit_constraints(): void
    {
        $id = $this->budget();
        $other = Household::factory()->create();
        $foreignCategory = app(CategoryService::class)->save(User::findOrFail($other->created_by_user_id), $other->id, ['name' => 'Other', 'type' => 'expense'])->id;
        foreach (['foreign', 'zero', 'day'] as $attempt) {
            try {
                DB::transaction(function () use ($attempt, $id, $foreignCategory): void {
                    if ($attempt === 'day') {
                        DB::table('budgets')->where('id', $id)->update(['month_start' => '2026-01-02']);
                    } else {
                        DB::table('budget_lines')->where('budget_id', $id)->update($attempt === 'foreign' ? ['category_id' => $foreignCategory] : ['limit_amount' => '0.00']);
                    }
                });
                $this->fail('Invalid budget data must fail.');
            } catch (QueryException $exception) {
                $this->assertSame($attempt === 'foreign' ? '23503' : '23514', $exception->errorInfo[0]);
            }
        }
    }

    public function test_usage_and_warning_keep_decimal_precision_above_javascript_safe_integer(): void
    {
        $this->budget('9007199254740993.25');
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income, 'amount' => '9007199254740993.25']))->assertCreated();
        $this->postOperation($this->payload(['amount' => '9007199254740993.25']))->assertCreated()->assertJsonPath('warnings.0.code', 'BUDGET_REACHED');
        $this->usage()->assertJsonPath('data.lines.0.used_amount', '9007199254740993.25')->assertJsonPath('data.lines.0.remaining_amount', '0.00');
    }

    public function test_removing_line_preserves_expense_as_unbudgeted_and_lower_limit_does_not_block(): void
    {
        $id = $this->budget();
        $this->operation();
        $other = app(CategoryService::class)->save($this->owner, $this->household->id, ['name' => 'Other', 'type' => 'expense'])->id;
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $other, 'limit_amount' => '10.00']]])->assertOk()->assertJsonPath('data.totals.unbudgeted_amount', '80000.00');
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '1.00']]])->assertOk()->assertJsonPath('data.lines.0.status', 'exceeded');
        $this->postOperation($this->payload(['amount' => '0.01']))->assertCreated()->assertJsonPath('warnings.0.code', 'BUDGET_EXCEEDED');
    }

    public function test_viewer_cannot_update_or_copy_but_can_read_existing_budget(): void
    {
        $id = $this->budget();
        $viewer = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'viewer']);
        $this->authenticate($viewer->user);
        $this->getJson($this->base('budgets').'/'.$id)->assertOk();
        $this->patchJson($this->base('budgets').'/'.$id, ['lines' => [['category_id' => $this->expense, 'limit_amount' => '1.00']]])->assertForbidden();
        $this->postJson($this->base('budgets/copy'), ['source_month' => '2026-01-01', 'target_month' => '2026-02-01'])->assertForbidden();
    }
}
