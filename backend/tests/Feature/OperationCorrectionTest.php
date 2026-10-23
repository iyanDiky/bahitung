<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CategoryService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OperationCorrectionTest extends TestCase
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

    public function test_void_keeps_original_and_appends_exact_opposite_with_audit(): void
    {
        $id = $this->operation();
        $before = DB::table('balance_movements')->where('operation_id', $id)->first();
        $this->change($id)->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonPath('wallet_balances.0.balance', '1000000.00');
        $this->assertDatabaseHas('balance_movements', ['id' => $before->id, 'amount_signed' => '-80000.00', 'entry_kind' => 'original']);
        $this->assertDatabaseHas('balance_movements', ['reversal_of_id' => $before->id, 'amount_signed' => '80000.00', 'entry_kind' => 'reversal']);
        $this->assertDatabaseHas('operation_reversals', ['operation_id' => $id, 'reason' => 'Salah catat']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operation.reversed']);
        $this->getJson($this->base().'/'.$id)->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonCount(2, 'movements');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_member_can_correct_own_old_transaction_and_chain_replacements(): void
    {
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate($member->user);
        $id = $this->operation();
        $new = $this->change($id, $this->payload(['amount' => '125000.00']))->assertCreated()->assertJsonPath('wallet_balances.0.balance', '875000.00')->json('data.replacement_operation_id');
        $this->assertDatabaseHas('operations', ['id' => $new, 'correction_of_id' => $id, 'status' => 'posted', 'created_by_member_id' => $member->id]);
        $this->change($new, $this->payload(['amount' => '100000.00']))->assertCreated()->assertJsonPath('wallet_balances.0.balance', '900000.00');
        $this->assertDatabaseCount('operation_reversals', 2);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_income_replacement_is_validated_net_without_temporary_negative_projection(): void
    {
        $id = $this->operation(['type' => 'income', 'category_id' => $this->income, 'wallet_id' => $this->destination, 'amount' => '100000.00']);
        $this->operation(['wallet_id' => $this->destination, 'amount' => '90000.00', 'occurred_on' => '2026-01-10']);
        $this->change($id)->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->change($id, $this->payload(['type' => 'income', 'category_id' => $this->income, 'wallet_id' => $this->destination, 'amount' => '120000.00']))->assertCreated()->assertJsonPath('wallet_balances.0.balance', '30000.00');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_moving_income_date_checks_intermediate_history_even_when_current_balance_positive(): void
    {
        $id = $this->operation(['type' => 'income', 'category_id' => $this->income, 'wallet_id' => $this->destination, 'amount' => '100000.00']);
        $this->operation(['wallet_id' => $this->destination, 'amount' => '90000.00', 'occurred_on' => '2026-01-10']);
        $this->change($id, $this->payload(['type' => 'income', 'category_id' => $this->income, 'wallet_id' => $this->destination, 'amount' => '120000.00', 'occurred_on' => '2026-01-20']))->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->assertDatabaseCount('operation_reversals', 0);
        $this->assertDatabaseHas('operations', ['id' => $id, 'status' => 'posted']);
    }

    public function test_void_transfer_reverses_principal_and_fee_without_double_counting(): void
    {
        $id = $this->postOperation($this->transfer())->assertCreated()->json('data.id');
        $this->change($id)->assertOk();
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '1000000.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '0.00']);
        $this->assertDatabaseHas('transfer_details', ['operation_id' => $id, 'fee_amount' => '2500.00']);
        $this->assertSame(2, DB::table('balance_movements')->where('operation_id', $id)->where('entry_kind', 'reversal')->count());
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_correct_transfer_fee_and_change_wallets(): void
    {
        $third = $this->wallet('0.00');
        $id = $this->postOperation($this->transfer())->assertCreated()->json('data.id');
        $new = $this->change($id, $this->transfer(['destination_wallet_id' => $third, 'fee_amount' => '1000.00', 'amount' => '400000.00']))->assertCreated()->json('data.replacement_operation_id');
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '599000.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '0.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $third, 'balance' => '400000.00']);
        $this->assertDatabaseHas('transfer_details', ['operation_id' => $new, 'fee_amount' => '1000.00']);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_replacing_spent_transfer_with_same_destination_uses_net_result(): void
    {
        $id = $this->postOperation($this->transfer())->assertCreated()->json('data.id');
        $this->operation(['wallet_id' => $this->destination, 'amount' => '450000.00']);
        $this->change($id)->assertUnprocessable();
        $this->change($id, $this->transfer(['amount' => '480000.00']))->assertCreated();
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '30000.00']);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_replay_conflict_and_already_reversed_are_distinct(): void
    {
        $id = $this->operation();
        $key = (string) Str::uuid();
        $first = $this->change($id, null, $key)->assertOk()->json();
        $this->operation();
        $this->assertEquals($first, $this->change($id, null, strtoupper($key))->assertOk()->json());
        $this->change($id, null, $key, 'Beda alasan')->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->change($id)->assertConflict()->assertJsonPath('error.code', 'ALREADY_REVERSED');
        $this->change($id, $this->payload(), $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_correction_replay_uses_canonical_payload_and_returns_original_response(): void
    {
        $id = $this->operation();
        $key = (string) Str::uuid();
        $first = $this->change($id, $this->transfer(['fee_amount' => '0.00', 'fee_category_id' => null]), $key)->assertCreated()->json();
        $replacement = $this->transfer();
        unset($replacement['fee_amount'], $replacement['fee_category_id']);
        $this->assertEquals($first, $this->change($id, $replacement, strtoupper($key))->assertCreated()->json());
        $other = $this->operation();
        $this->change($other, $replacement, $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('operation_reversals', 1);
    }

    #[TestWith(['owner', 200])]
    #[TestWith(['admin', 200])]
    #[TestWith(['member', 403])]
    #[TestWith(['viewer', 403])]
    public function test_role_permissions_for_other_members_operations(string $role, int $status): void
    {
        $id = $this->operation();
        $user = $role === 'owner' ? $this->owner : HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => $role])->user;
        $this->authenticate($user);
        $this->change($id)->assertStatus($status);
    }

    public function test_replay_rechecks_permission_after_member_role_changes(): void
    {
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate($member->user);
        $id = $this->operation();
        $key = (string) Str::uuid();
        $this->change($id, null, $key)->assertOk();
        $member->update(['role' => 'viewer']);
        $this->change($id, null, $key)->assertForbidden();
        $member->update(['status' => 'removed']);
        $this->change($id, null, $key)->assertNotFound();
    }

    public function test_foreign_household_operation_and_replacement_wallet_are_hidden(): void
    {
        $id = $this->operation();
        $foreign = Wallet::factory()->create();
        $this->change($id, $this->payload(['wallet_id' => $foreign->id]))->assertNotFound();
        $this->authenticate(User::findOrFail(Household::findOrFail($foreign->household_id)->created_by_user_id));
        $this->change($id)->assertNotFound();
        $this->assertDatabaseCount('operation_reversals', 0);
    }

    public function test_archived_original_can_be_voided_but_replacement_needs_active_wallet(): void
    {
        $id = $this->operation();
        $this->patchJson($this->base('wallets').'/'.$this->wallet, ['is_archived' => true])->assertOk();
        $this->change($id, $this->payload())->assertUnprocessable()->assertJsonPath('error.code', 'WALLET_ARCHIVED');
        $this->patchJson($this->base('categories').'/'.$this->expense, ['is_archived' => true])->assertOk();
        $this->change($id)->assertOk();
    }

    public function test_owner_can_correct_opening_without_classifying_it_as_income(): void
    {
        $id = DB::table('operations')->where('type', 'opening')->value('id');
        $new = $this->change($id, ['type' => 'opening', 'wallet_id' => $this->wallet, 'amount' => '9007199254740993.25', 'occurred_on' => '2026-01-01'])->assertCreated()->assertJsonPath('wallet_balances.0.balance', '9007199254740993.25')->json('data.replacement_operation_id');
        $this->assertDatabaseHas('operations', ['id' => $new, 'type' => 'opening']);
        $this->assertDatabaseMissing('operations', ['type' => 'income']);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_member_cannot_change_opening_even_if_they_created_it_as_former_admin(): void
    {
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'admin']);
        $wallet = app(WalletService::class)->create($member->user, $this->household->id, ['name' => 'Cash', 'type' => 'cash', 'opening_amount' => '100.00', 'idempotency_key' => (string) Str::uuid()]);
        $id = DB::table('balance_movements')->where('wallet_id', $wallet['data']['id'])->value('operation_id');
        $member->update(['role' => 'member']);
        $this->authenticate($member->user);
        $this->change($id)->assertForbidden();
    }

    public function test_opening_and_normal_operations_cannot_change_into_each_other(): void
    {
        $opening = DB::table('operations')->where('type', 'opening')->value('id');
        $this->change($opening, $this->payload())->assertUnprocessable();
        $id = $this->operation();
        $this->change($id, ['type' => 'opening', 'wallet_id' => $this->wallet, 'amount' => '1.00', 'occurred_on' => '2026-01-01'])->assertUnprocessable();
    }

    #[TestWith(['reason', ''])]
    #[TestWith(['reason', '   '])]
    #[TestWith(['reason', null])]
    #[TestWith(['replacement', 'invalid'])]
    #[TestWith(['replacement', []])]
    #[TestWith(['replacement', ['amount' => '1.00']])]
    public function test_reason_and_full_replacement_are_required(string $field, mixed $value): void
    {
        $id = $this->operation();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($this->base().'/'.$id.'/correct', array_replace(['reason' => 'Fix', 'replacement' => $this->payload()], [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('operation_reversals', 0);
    }

    public function test_replacement_validation_prohibits_protected_fields_and_invalid_transfer(): void
    {
        $id = $this->operation();
        $this->change($id, $this->payload(['amount' => 1.25]))->assertUnprocessable();
        $this->change($id, $this->payload(['correction_of_id' => 999]))->assertUnprocessable();
        $this->change($id, $this->transfer(['destination_wallet_id' => $this->wallet]))->assertUnprocessable();
        $this->change($id, $this->transfer(['fee_category_id' => null]))->assertUnprocessable();
        $this->change($id, $this->payload(['occurred_on' => '9999-12-31']))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', '')->postJson($this->base().'/'.$id.'/void', ['reason' => 'Fix', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseCount('operation_reversals', 0);
    }

    public function test_audit_failure_rolls_back_reversal_replacement_status_balances_and_receipt(): void
    {
        $id = $this->operation();
        $receipts = DB::table('idempotency_receipts')->count();
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_correction_audit CHECK (action <> 'operation.corrected')");
        $this->change($id, $this->payload(['amount' => '100000.00']))->assertStatus(500);
        $this->assertDatabaseHas('operations', ['id' => $id, 'status' => 'posted']);
        $this->assertDatabaseCount('operations', 2);
        $this->assertDatabaseCount('balance_movements', 2);
        $this->assertDatabaseCount('operation_reversals', 0);
        $this->assertDatabaseCount('idempotency_receipts', $receipts);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '920000.00']);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_database_requires_exact_opposite_and_immutable_reversal_record(): void
    {
        $id = $this->operation();
        $original = (array) DB::table('balance_movements')->where('operation_id', $id)->first();
        unset($original['id']);
        $original['reversal_of_id'] = DB::table('balance_movements')->where('operation_id', $id)->value('id');
        $original['entry_kind'] = 'reversal';
        $original['amount_signed'] = '1.00';
        try {
            DB::transaction(fn () => DB::table('balance_movements')->insert($original));
            $this->fail('Non-exact reversal must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->change($id)->assertOk();
        try {
            DB::transaction(fn () => DB::table('operation_reversals')->where('operation_id', $id)->delete());
            $this->fail('Deletion must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    #[TestWith(['void'])]
    #[TestWith(['correct'])]
    public function test_correction_endpoints_require_login(string $action): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->postJson($this->base().'/1/'.$action)->assertUnauthorized();
    }

    public function test_detail_shows_correction_link_reason_and_actor_without_internal_audit(): void
    {
        $id = $this->operation();
        $new = $this->change($id, $this->payload(['amount' => '50000.00']))->assertCreated()->json('data.replacement_operation_id');
        $viewer = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'viewer']);
        $this->authenticate($viewer->user);
        $this->getJson($this->base().'/'.$id)->assertOk()->assertJsonPath('reversal.reason', 'Salah catat')->assertJsonPath('reversal.replacement_operation_id', $new)->assertJsonMissingPath('audit_logs');
        $this->getJson($this->base().'/'.$new)->assertOk()->assertJsonPath('data.correction_of_id', $id)->assertJsonPath('reversal', null);
    }

    public function test_void_expense_rejects_balance_overflow_without_modifying_ledger(): void
    {
        $id = $this->operation();
        $this->operation(['type' => 'income', 'category_id' => $this->income, 'amount' => '99999999999079999.99']);
        $this->change($id)->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MONEY_AMOUNT');
        $this->assertDatabaseHas('operations', ['id' => $id, 'status' => 'posted']);
        $this->assertDatabaseCount('operation_reversals', 0);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_expense_replacement_before_opening_is_rejected(): void
    {
        $id = $this->operation();
        $this->change($id, $this->payload(['occurred_on' => '2025-12-31']))->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->assertDatabaseCount('operation_reversals', 0);
    }

    public function test_prior_reversals_do_not_get_subtracted_twice_when_posting_new_expense(): void
    {
        $id = $this->operation();
        $this->change($id)->assertOk();
        $this->operation(['amount' => '1000000.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '0.00']);
        $this->postOperation($this->payload(['amount' => '0.01']))->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }
}
