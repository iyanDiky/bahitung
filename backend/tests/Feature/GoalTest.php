<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\EloquentFinanceRepository;
use App\Services\GoalService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use DatabaseTransactions;

    private Household $household;

    private User $owner;

    private int $wallet;

    private int $goal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->household = Household::factory()->create();
        $this->owner = User::findOrFail($this->household->created_by_user_id);
        $this->wallet = app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Dompet', 'type' => 'bank', 'opening_amount' => '1000000.00', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->goal = app(GoalService::class)->save($this->owner, $this->household->id, ['name' => 'Pendidikan', 'target_amount' => '500000.00'])['id'];
        $this->authenticate($this->owner);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function base(string $resource = 'goals'): string
    {
        return '/api/v1/households/'.$this->household->id.'/'.$resource;
    }

    private function move(bool $withdraw = false, array $overrides = [], ?string $key = null, ?int $goal = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson($this->base().'/'.($goal ?? $this->goal).($withdraw ? '/withdrawals' : '/contributions'), array_replace([$withdraw ? 'destination_wallet_id' : 'wallet_id' => $this->wallet, 'amount' => '100000.00', 'occurred_on' => '2026-01-02'], $overrides));
    }

    private function change(int $id, ?array $replacement = null, ?string $key = null): TestResponse
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->postJson($this->base('operations').'/'.$id.($replacement ? '/correct' : '/void'), ['reason' => 'Perbaikan catatan', ...($replacement ? ['replacement' => $replacement] : [])]);
    }

    private function balances(string $wallet, string $goal): void
    {
        $this->assertSame($wallet, DB::table('wallet_balances')->where('wallet_id', $this->wallet)->value('balance'));
        $this->assertSame($goal, DB::table('goal_balances')->where('goal_id', $this->goal)->value('balance'));
        $this->artisan('wallets:reconcile')->assertSuccessful();
        $this->artisan('goals:reconcile')->assertSuccessful();
    }

    public function test_metadata_progress_archive_and_filters(): void
    {
        $this->getJson($this->base().'/'.$this->goal)->assertOk()->assertJsonPath('data.balance', '0.00')->assertJsonPath('data.remaining_amount', '500000.00')->assertJsonPath('data.percent_saved', '0.00')->assertJsonPath('data.is_reached', false);
        $this->move(false, ['amount' => '600000.00'])->assertCreated();
        $this->getJson($this->base().'/'.$this->goal)->assertJsonPath('data.percent_saved', '120.00')->assertJsonPath('data.is_reached', true)->assertJsonPath('data.remaining_amount', '0.00');
        $this->patchJson($this->base().'/'.$this->goal, ['name' => 'Sekolah', 'target_date' => '2027-01-01', 'target_amount' => '800000.00', 'is_archived' => true])->assertOk()->assertJsonPath('data.balance', '600000.00')->assertJsonPath('data.percent_saved', '75.00');
        $this->getJson($this->base().'?status=active')->assertJsonCount(0, 'data');
        $this->getJson($this->base().'?status=archived&per_page=1')->assertJsonCount(1, 'data');
        $this->move()->assertUnprocessable()->assertJsonPath('error.code', 'GOAL_ARCHIVED');
        $this->move(true)->assertUnprocessable()->assertJsonPath('error.code', 'GOAL_ARCHIVED');
        $this->patchJson($this->base().'/'.$this->goal, ['is_archived' => false, 'target_date' => null])->assertOk()->assertJsonPath('data.target_date', null);
        $this->move(true)->assertCreated();
        $this->balances('500000.00', '500000.00');
    }

    public function test_contribution_withdrawal_preserve_assets_and_never_count_as_budget_expense(): void
    {
        $deposit = $this->move()->assertCreated()->assertJsonCount(2, 'movements')->assertJsonPath('data.type', 'goal_contribution')->assertJsonPath('data.goal_id', $this->goal)->assertJsonPath('warnings', []);
        $this->assertSame('-100000.00', $deposit->json('movements.0.amount_signed'));
        $this->assertNull($deposit->json('movements.1.wallet_id'));
        $this->assertSame($this->goal, $deposit->json('movements.1.goal_id'));
        $this->balances('900000.00', '100000.00');
        $this->move(true, ['amount' => '25000.50'])->assertCreated()->assertJsonPath('goal_balances.0.balance', '74999.50');
        $this->balances('925000.50', '74999.50');
        $this->getJson($this->base('reports/budget-usage').'?month_start=2026-01-01')->assertOk()->assertJsonPath('data.totals.used_amount', '0.00')->assertJsonPath('data.totals.unbudgeted_amount', '0.00');
        $this->getJson($this->base('operations').'?goal_id='.$this->goal)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->base().'/'.$this->goal.'/movements?from=2026-01-02&to=2026-01-02')->assertOk()->assertJsonCount(2, 'data');
    }

    #[TestWith(['owner', true, true])]
    #[TestWith(['admin', true, true])]
    #[TestWith(['member', false, true])]
    #[TestWith(['viewer', false, false])]
    public function test_role_permissions(string $role, bool $manage, bool $contribute): void
    {
        $this->move()->assertCreated();
        $actor = $this->owner;
        if ($role !== 'owner') {
            $actor = User::factory()->create();
            HouseholdMember::factory()->create(['household_id' => $this->household->id, 'user_id' => $actor->id, 'role' => $role]);
        }
        $this->authenticate($actor);
        $this->getJson($this->base())->assertOk();
        $this->getJson($this->base().'/'.$this->goal.'/movements')->assertOk();
        $this->postJson($this->base(), ['name' => 'Baru', 'target_amount' => '1.00'])->assertStatus($manage ? 201 : 403);
        $this->patchJson($this->base().'/'.$this->goal, ['name' => 'Ganti'])->assertStatus($manage ? 200 : 403);
        $deposit = $this->move()->assertStatus($contribute ? 201 : 403);
        $this->move(true)->assertStatus($manage ? 201 : 403);
        if ($contribute) {
            $this->change($deposit->json('data.id'))->assertStatus($manage ? 200 : 403);
        }
    }

    public function test_cross_household_resources_and_revoked_membership_are_hidden(): void
    {
        $other = Household::factory()->create();
        $user = User::findOrFail($other->created_by_user_id);
        $goal = app(GoalService::class)->save($user, $other->id, ['name' => 'Private', 'target_amount' => '1.00'])['id'];
        $wallet = app(WalletService::class)->create($user, $other->id, ['name' => 'Other', 'type' => 'cash', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->getJson($this->base().'/'.$goal)->assertNotFound();
        $this->move(goal: $goal)->assertNotFound();
        $this->move(overrides: ['wallet_id' => $wallet])->assertNotFound();
        $this->getJson('/api/v1/households/'.$other->id.'/goals')->assertNotFound();
        $this->balances('1000000.00', '0.00');
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate(User::findOrFail($member->user_id));
        $key = (string) Str::uuid();
        $this->move(key: $key)->assertCreated();
        $member->update(['status' => 'removed']);
        $this->move(key: $key)->assertNotFound();
    }

    public function test_receipt_replay_and_conflicts_are_shared_with_all_financial_endpoints(): void
    {
        $key = (string) Str::uuid();
        $first = $this->move(key: $key)->assertCreated()->json();
        $this->patchJson($this->base().'/'.$this->goal, ['is_archived' => true])->assertOk();
        $this->assertEquals($first, $this->move(key: $key)->assertCreated()->json());
        $this->move(overrides: ['amount' => '1.00'], key: $key)->assertConflict();
        $this->move(true, key: $key)->assertConflict();
        $another = app(GoalService::class)->save($this->owner, $this->household->id, ['name' => 'Other', 'target_amount' => '10.00'])['id'];
        $this->move(key: $key, goal: $another)->assertConflict();
        $this->balances('900000.00', '100000.00');
    }

    public function test_insufficient_and_historical_balances_roll_back_both_sides(): void
    {
        $this->move(overrides: ['amount' => '1000000.01'])->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
        $this->move(overrides: ['occurred_on' => '2025-12-31'])->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->move()->assertCreated();
        $this->move(true, ['amount' => '100000.01'])->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
        $this->move(true, ['occurred_on' => '2026-01-01'])->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->balances('900000.00', '100000.00');
        $this->assertSame(2, DB::table('operations')->where('household_id', $this->household->id)->count());
    }

    public function test_correction_reverses_both_accounts_and_replays_after_other_changes(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $replacement = ['type' => 'goal_contribution', 'goal_id' => $this->goal, 'wallet_id' => $this->wallet, 'amount' => '150000.25', 'occurred_on' => '2026-01-03'];
        $first = $this->change($id, $replacement, $key)->assertCreated()->json();
        $this->balances('849999.75', '150000.25');
        $this->assertEquals($first, $this->change($id, $replacement, $key)->assertCreated()->json());
        $this->getJson($this->base('operations').'/'.$id)->assertJsonPath('data.status', 'reversed')->assertJsonCount(4, 'movements');
        $this->change($first['data']['replacement_operation_id'])->assertOk();
        $this->balances('1000000.00', '0.00');
        $this->change($id)->assertConflict();
    }

    public function test_correction_cannot_remove_goal_funds_already_withdrawn_or_change_type(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $this->move(true, ['amount' => '90000.00', 'occurred_on' => '2026-01-03'])->assertCreated();
        $this->move(overrides: ['amount' => '100000.00', 'occurred_on' => '2026-01-04'])->assertCreated();
        $this->change($id)->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->change($id, ['type' => 'goal_withdrawal', 'goal_id' => $this->goal, 'destination_wallet_id' => $this->wallet, 'amount' => '1.00', 'occurred_on' => '2026-01-02'])->assertUnprocessable();
        $this->balances('890000.00', '110000.00');
    }

    public function test_withdrawal_correction_validates_both_histories(): void
    {
        $this->move()->assertCreated();
        $id = $this->move(true, ['amount' => '40000.00', 'occurred_on' => '2026-01-03'])->assertCreated()->json('data.id');
        $this->change($id, ['type' => 'goal_withdrawal', 'goal_id' => $this->goal, 'destination_wallet_id' => $this->wallet, 'amount' => '60000.00', 'occurred_on' => '2026-01-03'])->assertCreated();
        $this->balances('960000.00', '40000.00');
    }

    #[TestWith([0])]
    #[TestWith(['0.00'])]
    #[TestWith(['-1.00'])]
    #[TestWith(['1.001'])]
    #[TestWith(['1e3'])]
    #[TestWith(['100000000000000000.00'])]
    public function test_invalid_money_is_rejected(mixed $value): void
    {
        $this->postJson($this->base(), ['name' => 'Test', 'target_amount' => $value])->assertUnprocessable();
        $this->move(overrides: ['amount' => $value])->assertUnprocessable();
        $this->balances('1000000.00', '0.00');
    }

    public function test_dates_keys_and_direct_balance_writes_are_rejected(): void
    {
        $this->move(overrides: ['occurred_on' => '9999-01-01'])->assertUnprocessable();
        $this->move(overrides: ['occurred_on' => '2026-02-30'])->assertUnprocessable();
        $this->move(key: 'invalid')->assertUnprocessable();
        $this->patchJson($this->base().'/'.$this->goal, ['balance' => '100.00'])->assertUnprocessable();
        $this->move(overrides: ['goal_id' => $this->goal])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base('operations'), ['type' => 'goal_contribution', 'wallet_id' => $this->wallet, 'goal_id' => $this->goal, 'amount' => '1.00', 'occurred_on' => '2026-01-02'])->assertUnprocessable();
    }

    public function test_precision_and_balance_overflow(): void
    {
        $this->wallet = app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Large', 'type' => 'bank', 'opening_amount' => '99999999999999999.99', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->move(overrides: ['amount' => '9007199254740993.25'])->assertCreated();
        $this->balances('90992800745259006.74', '9007199254740993.25');
        $this->move(true, ['amount' => '9007199254740993.25'])->assertCreated();
        $this->balances('99999999999999999.99', '0.00');
    }

    public function test_audit_failure_rolls_back_goal_posting_and_receipt(): void
    {
        $this->partialMock(EloquentFinanceRepository::class, function ($mock): void {
            $mock->shouldReceive('audit')->andThrow(new \RuntimeException('Audit unavailable'));
        });
        $this->app->bind(FinanceRepository::class, fn () => $this->app->make(EloquentFinanceRepository::class));
        $this->move()->assertStatus(500);
        $this->balances('1000000.00', '0.00');
        $this->assertSame(1, DB::table('operations')->where('household_id', $this->household->id)->count());
    }

    public function test_database_requires_exactly_one_account_and_exact_goal_reversal(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $original = DB::table('balance_movements')->where('operation_id', $id)->whereNotNull('goal_id')->first();
        foreach ([['wallet_id' => $this->wallet, 'goal_id' => $this->goal, 'amount_signed' => '1.00', 'entry_kind' => 'original'], ['wallet_id' => null, 'goal_id' => null, 'amount_signed' => '1.00', 'entry_kind' => 'original'], ['goal_id' => $this->goal, 'amount_signed' => '-1.00', 'entry_kind' => 'reversal', 'reversal_of_id' => $original->id]] as $row) {
            try {
                DB::transaction(fn () => DB::table('balance_movements')->insert(['household_id' => $this->household->id, 'operation_id' => $id, 'created_at' => now(), ...$row]));
                $this->fail('Invalid movement accepted');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        try {
            DB::transaction(fn () => DB::table('balance_movements')->where('id', $original->id)->update(['amount_signed' => '1.00']));
            $this->fail('Ledger was mutable');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->balances('900000.00', '100000.00');
    }

    public function test_reconciliation_detects_goal_projection_drift(): void
    {
        $this->move()->assertCreated();
        DB::table('goal_balances')->where('goal_id', $this->goal)->update(['balance' => '1.00']);
        $this->artisan('goals:reconcile')->assertFailed();
        $this->assertSame('1.00', DB::table('goal_balances')->where('goal_id', $this->goal)->value('balance'));
    }

    public function test_correction_can_move_contribution_between_targets_without_double_counting(): void
    {
        $other = app(GoalService::class)->save($this->owner, $this->household->id, ['name' => 'Other', 'target_amount' => '100000.00'])['id'];
        $id = $this->move()->assertCreated()->json('data.id');
        $this->change($id, ['type' => 'goal_contribution', 'wallet_id' => $this->wallet, 'goal_id' => $other, 'amount' => '150000.00', 'occurred_on' => '2026-01-02'])->assertCreated();
        $this->balances('850000.00', '0.00');
        $this->getJson($this->base().'/'.$other)->assertOk()->assertJsonPath('data.balance', '150000.00');
    }

    public function test_archived_wallet_rejects_new_movements_but_void_can_restore_archived_accounts(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $this->patchJson($this->base('wallets').'/'.$this->wallet, ['is_archived' => true])->assertOk();
        $this->move()->assertUnprocessable()->assertJsonPath('error.code', 'WALLET_ARCHIVED');
        $this->move(true)->assertUnprocessable()->assertJsonPath('error.code', 'WALLET_ARCHIVED');
        $this->patchJson($this->base().'/'.$this->goal, ['is_archived' => true])->assertOk();
        $this->change($id)->assertOk();
        $this->balances('1000000.00', '0.00');
    }

    public function test_goal_overflow_is_rejected_before_wallet_debit(): void
    {
        $largeWallet = app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Large', 'type' => 'bank', 'opening_amount' => '99999999999999999.99', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->move(overrides: ['wallet_id' => $largeWallet, 'amount' => '99999999999999999.99'])->assertCreated();
        $this->move(overrides: ['amount' => '0.01'])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MONEY_AMOUNT');
        $this->balances('1000000.00', '99999999999999999.99');
    }

    public function test_future_goal_history_peak_also_prevents_backdated_overflow(): void
    {
        $largeWallet = app(WalletService::class)->create($this->owner, $this->household->id, ['name' => 'Large', 'type' => 'bank', 'opening_amount' => '99999999999999999.99', 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->move(overrides: ['wallet_id' => $largeWallet, 'amount' => '99999999999999999.99', 'occurred_on' => '2026-01-03'])->assertCreated();
        $this->move(true, ['destination_wallet_id' => $largeWallet, 'amount' => '1.00', 'occurred_on' => '2026-01-04'])->assertCreated();
        $this->move(overrides: ['amount' => '0.01', 'occurred_on' => '2026-01-02'])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MONEY_AMOUNT');
        $this->balances('1000000.00', '99999999999999998.99');
    }

    public function test_failed_correction_does_not_append_reversal_or_remember_response(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $before = DB::table('idempotency_receipts')->where('household_id', $this->household->id)->count();
        $this->partialMock(EloquentFinanceRepository::class, function ($mock): void {
            $mock->shouldReceive('audit')->andThrow(new \RuntimeException('Audit unavailable'));
        });
        $this->app->bind(FinanceRepository::class, fn () => $this->app->make(EloquentFinanceRepository::class));
        $this->change($id)->assertStatus(500);
        $this->assertSame('posted', DB::table('operations')->where('id', $id)->value('status'));
        $this->assertSame(0, DB::table('operation_reversals')->where('operation_id', $id)->count());
        $this->assertSame($before, DB::table('idempotency_receipts')->where('household_id', $this->household->id)->count());
        $this->balances('900000.00', '100000.00');
    }

    public function test_foreign_household_goal_is_rejected_by_database_constraint(): void
    {
        $id = $this->move()->assertCreated()->json('data.id');
        $other = Household::factory()->create();
        $goal = app(GoalService::class)->save(User::findOrFail($other->created_by_user_id), $other->id, ['name' => 'Other', 'target_amount' => '1.00'])['id'];
        try {
            DB::transaction(fn () => DB::table('balance_movements')->insert(['household_id' => $this->household->id, 'operation_id' => $id, 'goal_id' => $goal, 'amount_signed' => '1.00', 'entry_kind' => 'original', 'created_at' => now()]));
            $this->fail('Cross-household movement accepted');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0]);
        }
    }
}
