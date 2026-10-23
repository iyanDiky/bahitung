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

class OperationPostingTest extends TestCase
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

    public function test_transfer_fee_is_linked_expense_and_atomic_balanced_principal(): void
    {
        $response = $this->postOperation($this->transfer())->assertCreated()->assertJsonPath('data.amount', '500000.00')->assertJsonPath('data.fee_amount', '2500.00')->assertJsonPath('movements.0.amount_signed', '-502500.00')->assertJsonPath('movements.1.amount_signed', '500000.00');
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '497500.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '500000.00']);
        $this->assertDatabaseHas('transfer_details', ['operation_id' => $response->json('data.id'), 'fee_amount' => '2500.00', 'fee_category_id' => $this->expense]);
        $this->assertDatabaseMissing('operations', ['type' => 'income']);
        $this->assertDatabaseMissing('operations', ['type' => 'expense']);
        $this->assertSame('997500.00', DB::table('wallet_balances')->selectRaw('SUM(balance)::text AS total')->value('total'));
        $this->getJson($this->base().'/'.$response->json('data.id'))->assertOk()->assertJsonCount(2, 'movements')->assertJsonMissingPath('data.payload_hash');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_income_and_expense_preserve_precision_and_category(): void
    {
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income, 'amount' => '9007199254740993.25']))->assertCreated()->assertJsonPath('wallet_balances.0.balance', '9007199255740993.25');
        $this->postOperation($this->payload(['amount' => '0.01']))->assertCreated()->assertJsonPath('wallet_balances.0.balance', '9007199255740993.24');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_transfer_without_fee_needs_no_category(): void
    {
        $payload = $this->transfer();
        unset($payload['fee_amount'], $payload['fee_category_id']);
        $this->postOperation($payload)->assertCreated()->assertJsonPath('data.fee_amount', '0.00');
    }

    public function test_replay_returns_original_result_after_balance_and_archive_change(): void
    {
        $key = (string) Str::uuid();
        $first = $this->postOperation($this->payload(), $key)->assertCreated()->json();
        $this->postOperation($this->payload(['amount' => '100.00']))->assertCreated();
        $this->patchJson($this->base('wallets').'/'.$this->wallet, ['is_archived' => true])->assertOk();
        $second = $this->postOperation($this->payload(), strtoupper($key))->assertCreated()->json();
        $this->assertEquals($first, $second);
        $this->postOperation($this->payload(['amount' => '1.00']), $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseCount('operations', 3);
    }

    public function test_idempotency_key_cannot_be_reused_across_endpoints(): void
    {
        $key = DB::table('idempotency_receipts')->where('endpoint', 'wallets.store')->value('key');
        $this->postOperation($this->payload(), $key)->assertConflict();
    }

    #[TestWith(['0.00'])]
    #[TestWith(['-1.00'])]
    #[TestWith(['1e2'])]
    #[TestWith(['01.00'])]
    #[TestWith(['1.001'])]
    #[TestWith([100.25])]
    #[TestWith(['100000000000000000.00'])]
    public function test_invalid_money_never_posts(mixed $amount): void
    {
        $this->postOperation($this->payload(['amount' => $amount]))->assertUnprocessable();
        $this->assertDatabaseCount('operations', 1);
    }

    #[TestWith(['type', 'opening'])]
    #[TestWith(['type', 'adjustment'])]
    #[TestWith(['occurred_on', '9999-12-31'])]
    #[TestWith(['occurred_on', '0000-01-01'])]
    #[TestWith(['status', 'reversed'])]
    #[TestWith(['created_by_member_id', 999])]
    #[TestWith(['household_id', 999])]
    #[TestWith(['source_wallet_id', 1])]
    public function test_protected_and_invalid_fields_are_rejected(string $field, mixed $value): void
    {
        $this->postOperation($this->payload([$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_header_required_and_fee_category_required_and_wallets_distinct(): void
    {
        $this->postJson($this->base(), $this->payload(['idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->postOperation($this->transfer(['fee_category_id' => null]))->assertUnprocessable();
        $this->postOperation($this->transfer(['destination_wallet_id' => $this->wallet]))->assertUnprocessable();
        $this->postOperation($this->transfer(['fee_amount' => '-1.00']))->assertUnprocessable();
        $this->postOperation($this->transfer(['fee_category_id' => $this->income]))->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CATEGORY_TYPE');
    }

    public function test_insufficient_balance_including_fee_and_overflow_change_nothing(): void
    {
        $this->postOperation($this->transfer(['amount' => '1000000.00']))->assertUnprocessable()->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income, 'amount' => '99999999999999999.99']))->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_MONEY_AMOUNT');
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('transfer_details', 0);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '1000000.00']);
    }

    public function test_backdated_debit_before_opening_rejected_even_with_current_funds(): void
    {
        $this->postOperation($this->payload(['occurred_on' => '2025-12-31']))->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->postOperation($this->payload(['occurred_on' => '2026-01-01']))->assertCreated();
    }

    public function test_backdated_debit_checks_intermediate_minimum_not_only_current_balance(): void
    {
        $this->postOperation($this->payload(['amount' => '990000.00', 'occurred_on' => '2026-01-10']))->assertCreated();
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income, 'amount' => '1000000.00', 'occurred_on' => '2026-01-20']))->assertCreated();
        $this->postOperation($this->payload(['occurred_on' => '2026-01-05']))->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->postOperation($this->payload(['amount' => '10000.00', 'occurred_on' => '2026-01-05']))->assertCreated();
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_category_archive_type_and_wallet_archive_validations(): void
    {
        $this->postOperation($this->payload(['category_id' => $this->income]))->assertUnprocessable();
        $this->patchJson($this->base('categories').'/'.$this->expense, ['is_archived' => true])->assertOk();
        $this->postOperation($this->payload())->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CATEGORY_TYPE');
        $this->patchJson($this->base('categories').'/'.$this->expense, ['is_archived' => false])->assertOk();
        $this->patchJson($this->base('wallets').'/'.$this->wallet, ['is_archived' => true])->assertOk();
        $this->postOperation($this->payload())->assertUnprocessable()->assertJsonPath('error.code', 'WALLET_ARCHIVED');
    }

    #[TestWith(['owner', 201])]
    #[TestWith(['admin', 201])]
    #[TestWith(['member', 201])]
    #[TestWith(['viewer', 403])]
    public function test_posting_role_matrix(string $role, int $status): void
    {
        $user = $role === 'owner' ? $this->owner : HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => $role])->user;
        $this->authenticate($user);
        $this->getJson($this->base())->assertOk();
        $this->postOperation($this->payload())->assertStatus($status);
    }

    public function test_tenant_isolation_and_removed_membership_apply_to_reads_and_writes(): void
    {
        $other = Household::factory()->create();
        $foreignOwner = User::findOrFail($other->created_by_user_id);
        $category = app(CategoryService::class)->save($foreignOwner, $other->id, ['name' => 'Foreign', 'type' => 'expense']);
        $foreignWallet = app(WalletService::class)->create($foreignOwner, $other->id, ['name' => 'Foreign', 'type' => 'cash', 'idempotency_key' => (string) Str::uuid()])['data']['id'];
        $this->postOperation($this->payload(['category_id' => $category->id]))->assertNotFound();
        $this->postOperation($this->payload(['wallet_id' => $foreignWallet]))->assertNotFound();
        $id = $this->postOperation($this->payload())->assertCreated()->json('data.id');
        $this->authenticate($foreignOwner);
        $this->getJson($this->base().'/'.$id)->assertNotFound();
        $this->postOperation($this->payload())->assertNotFound();
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'status' => 'removed']);
        $this->authenticate($member->user);
        $this->getJson($this->base())->assertNotFound();
        $this->postOperation($this->payload())->assertNotFound();
    }

    public function test_filters_include_transfer_fee_category_and_both_wallets(): void
    {
        $this->postOperation($this->transfer())->assertCreated();
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income]))->assertCreated();
        $this->getJson($this->base().'?wallet_id='.$this->destination.'&category_id='.$this->expense.'&from=2026-01-02&to=2026-01-02')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'transfer');
        $this->getJson($this->base().'?type=income&per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($this->base().'?per_page=101')->assertUnprocessable();
        $this->getJson($this->base().'?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_failed_audit_rolls_back_both_wallets_details_ledger_and_receipt(): void
    {
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_operation CHECK (action <> 'operation.posted')");
        $this->postOperation($this->transfer())->assertStatus(500);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('balance_movements', 1);
        $this->assertDatabaseCount('transfer_details', 0);
        $this->assertDatabaseCount('idempotency_receipts', 2);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->wallet, 'balance' => '1000000.00']);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '0.00']);
    }

    public function test_category_management_unique_names_type_immutable_and_role_scoping(): void
    {
        $url = $this->base('categories');
        $this->postJson($url, ['name' => 'BELANJA', 'type' => 'expense'])->assertConflict();
        $this->postJson($url, ['name' => 'Belanja', 'type' => 'income'])->assertCreated();
        $this->patchJson($url.'/'.$this->expense, ['type' => 'income'])->assertUnprocessable();
        $this->patchJson($url.'/'.$this->expense, ['name' => 'Dapur', 'is_archived' => true])->assertOk();
        $this->getJson($url.'?status=archived&type=expense')->assertOk()->assertJsonCount(1, 'data');
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate($member->user);
        $this->getJson($url)->assertOk();
        $this->postJson($url, ['name' => 'New', 'type' => 'expense'])->assertForbidden();
        $this->patchJson($url.'/'.$this->expense, ['name' => 'New'])->assertForbidden();
    }

    public function test_replay_requires_current_permission(): void
    {
        $member = HouseholdMember::factory()->create(['household_id' => $this->household->id, 'role' => 'member']);
        $this->authenticate($member->user);
        $key = (string) Str::uuid();
        $this->postOperation($this->payload(), $key)->assertCreated();
        $member->update(['role' => 'viewer']);
        $this->postOperation($this->payload(), $key)->assertForbidden();
        $member->update(['status' => 'removed']);
        $this->postOperation($this->payload(), $key)->assertNotFound();
    }

    public function test_archived_destination_and_historical_fee_conflict_leave_no_partial_debit(): void
    {
        $this->patchJson($this->base('wallets').'/'.$this->destination, ['is_archived' => true])->assertOk();
        $this->postOperation($this->transfer())->assertUnprocessable()->assertJsonPath('error.code', 'WALLET_ARCHIVED');
        $this->patchJson($this->base('wallets').'/'.$this->destination, ['is_archived' => false])->assertOk();
        $this->postOperation($this->payload(['amount' => '500000.00', 'occurred_on' => '2026-01-10']))->assertCreated();
        $this->postOperation($this->payload(['type' => 'income', 'category_id' => $this->income, 'amount' => '1000000.00', 'occurred_on' => '2026-01-20']))->assertCreated();
        $this->postOperation($this->transfer(['occurred_on' => '2026-01-05']))->assertUnprocessable()->assertJsonPath('error.code', 'HISTORICAL_BALANCE_CONFLICT');
        $this->assertDatabaseCount('transfer_details', 0);
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $this->destination, 'balance' => '0.00']);
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_database_blocks_foreign_category_and_mutation_of_transfer_details(): void
    {
        $id = $this->postOperation($this->transfer())->assertCreated()->json('data.id');
        $other = Household::factory()->create();
        $category = app(CategoryService::class)->save(User::findOrFail($other->created_by_user_id), $other->id, ['name' => 'Other', 'type' => 'expense']);
        foreach (['foreign', 'update', 'delete'] as $action) {
            try {
                DB::transaction(function () use ($action, $id, $category): void {
                    if ($action === 'foreign') {
                        DB::table('operations')->where('id', $id)->update(['category_id' => $category->id]);
                    } elseif ($action === 'update') {
                        DB::table('transfer_details')->where('operation_id', $id)->update(['fee_amount' => '1.00']);
                    } else {
                        DB::table('transfer_details')->where('operation_id', $id)->delete();
                    }
                });
                $this->fail('Database must reject invalid mutation.');
            } catch (QueryException $exception) {
                $this->assertSame($action === 'foreign' ? '23503' : '23514', $exception->errorInfo[0]);
            }
        }
    }

    #[TestWith(['GET', 'categories'])]
    #[TestWith(['POST', 'categories'])]
    #[TestWith(['PATCH', 'categories/1'])]
    #[TestWith(['GET', 'operations'])]
    #[TestWith(['POST', 'operations'])]
    #[TestWith(['GET', 'operations/1'])]
    public function test_finance_endpoints_require_login(string $method, string $resource): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->json($method, $this->base($resource))->assertUnauthorized();
    }
}
