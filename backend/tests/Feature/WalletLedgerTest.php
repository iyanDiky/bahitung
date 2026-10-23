<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(Household $household): User
    {
        return User::findOrFail($household->created_by_user_id);
    }

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function createWallet(Household $household, string $amount = '125000.25'): array
    {
        return app(WalletService::class)->create($this->owner($household), $household->id, ['name' => 'BCA', 'type' => 'bank', 'opening_amount' => $amount, 'opening_on' => '2026-01-01', 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_opening_is_exact_ledger_credit_not_income(): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/households/'.$household->id.'/wallets', ['name' => 'BCA', 'type' => 'bank', 'opening_amount' => '9007199254740993.25', 'opening_on' => '2026-01-01'])->assertCreated()->assertJsonPath('data.balance', '9007199254740993.25');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $id, 'balance' => '9007199254740993.25']);
        $this->assertDatabaseHas('operations', ['type' => 'opening', 'amount' => '9007199254740993.25', 'occurred_on' => '2026-01-01']);
        $this->assertDatabaseMissing('operations', ['type' => 'income']);
        $this->assertDatabaseHas('balance_movements', ['wallet_id' => $id, 'amount_signed' => '9007199254740993.25']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'wallet.created']);
        $this->getJson('/api/v1/households/'.$household->id.'/wallets/'.$id.'/movements')->assertOk()->assertJsonPath('data.0.amount_signed', '9007199254740993.25')->assertJsonPath('data.0.type', 'opening');
        $this->artisan('wallets:reconcile')->expectsOutput('Saldo dompet sesuai ledger.')->assertSuccessful();
    }

    public function test_maximum_supported_amount_is_not_rounded(): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/households/'.$household->id.'/wallets', ['name' => 'Maximum', 'type' => 'other', 'opening_amount' => '99999999999999999.99'])
            ->assertCreated()->assertJsonPath('data.balance', '99999999999999999.99');
        $this->artisan('wallets:reconcile')->assertSuccessful();
    }

    public function test_zero_opening_creates_no_financial_operation(): void
    {
        $household = Household::factory()->create();
        $result = $this->createWallet($household, '0.00');
        $this->assertSame('0.00', $result['data']['balance']);
        $this->assertDatabaseCount('operations', 0);
        $this->assertDatabaseCount('balance_movements', 0);
        $this->assertDatabaseCount('idempotency_receipts', 1);
    }

    public function test_duplicate_key_replays_original_response_even_after_wallet_metadata_changed(): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $key = (string) Str::uuid();
        $payload = ['name' => 'Tunai', 'type' => 'cash', 'opening_amount' => '100.01'];
        $base = '/api/v1/households/'.$household->id.'/wallets';
        $first = $this->withHeader('Idempotency-Key', $key)->postJson($base, $payload)->assertCreated();
        $this->patchJson($base.'/'.$first->json('data.id'), ['name' => 'Nama baru'])->assertOk();
        $this->travel(1)->days();
        $second = $this->withHeader('Idempotency-Key', strtoupper($key))->postJson($base, $payload)->assertCreated();
        $this->assertEquals($first->json(), $second->json());
        $this->assertDatabaseCount('wallets', 1);
        $this->assertDatabaseCount('operations', 1);
        $this->assertDatabaseCount('balance_movements', 1);
        $this->postJson($base, [...$payload, 'opening_amount' => '200.00'])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDatabaseHas('wallet_balances', ['balance' => '100.01']);
    }

    public function test_same_key_is_independent_across_households(): void
    {
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        foreach ([1, 2] as $unused) {
            $household = Household::factory()->create(['created_by_user_id' => $user->id]);
            app(WalletService::class)->create($user, $household->id, ['name' => 'Wallet', 'type' => 'cash', 'idempotency_key' => $key]);
        }
        $this->assertDatabaseCount('wallets', 2);
    }

    #[TestWith(['-1.00'])]
    #[TestWith(['1'])]
    #[TestWith(['1.001'])]
    #[TestWith(['1e3'])]
    #[TestWith(['01.00'])]
    #[TestWith(['100000000000000000.00'])]
    #[TestWith([100.25])]
    #[TestWith([null])]
    public function test_invalid_money_creates_nothing(mixed $amount): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/households/'.$household->id.'/wallets', ['name' => 'Wallet', 'type' => 'cash', 'opening_amount' => $amount])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['opening_amount']]]);
        $this->assertDatabaseCount('wallets', 0);
    }

    public function test_idempotency_header_is_required_and_cannot_be_supplied_in_body(): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $this->postJson('/api/v1/households/'.$household->id.'/wallets', ['name' => 'Wallet', 'type' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error.details.idempotency_key.0', 'Header Idempotency-Key wajib diisi.');
    }

    #[TestWith(['name', ''])]
    #[TestWith(['type', 'private'])]
    #[TestWith(['balance', '1000.00'])]
    #[TestWith(['household_id', 123])]
    #[TestWith(['created_by_member_id', 123])]
    #[TestWith(['opening_on', '9999-12-31'])]
    #[TestWith(['opening_on', '0000-01-01'])]
    public function test_wallet_validation_rejects_invalid_and_protected_fields(string $field, mixed $value): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/households/'.$household->id.'/wallets', array_merge(['name' => 'Wallet', 'type' => 'cash'], [$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('idempotency_receipts', 0);
    }

    public function test_opening_default_date_uses_household_timezone(): void
    {
        $this->travelTo(Carbon::parse('2026-01-01 18:00:00', 'UTC'));
        $household = Household::factory()->create(['timezone' => 'Asia/Makassar']);
        app(WalletService::class)->create($this->owner($household), $household->id, ['name' => 'Wallet', 'type' => 'cash', 'opening_amount' => '1.00', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertDatabaseHas('operations', ['occurred_on' => '2026-01-02']);
    }

    #[TestWith(['owner', true])]
    #[TestWith(['admin', true])]
    #[TestWith(['member', false])]
    #[TestWith(['viewer', false])]
    public function test_wallet_policy_matrix(string $role, bool $write): void
    {
        $household = Household::factory()->create();
        $user = $role === 'owner' ? $this->owner($household) : HouseholdMember::factory()->create(['household_id' => $household->id, 'role' => $role])->user;
        $this->assertTrue(Gate::forUser($user)->allows('view-wallets', $household));
        $this->assertSame($write, Gate::forUser($user)->allows('manage-wallets', $household));
        $this->assertSame(404, Gate::forUser($user)->inspect('view-wallets', Household::factory()->create())->status());
    }

    public function test_viewer_can_read_but_cannot_create_or_edit(): void
    {
        $member = HouseholdMember::factory()->create(['role' => 'viewer']);
        $result = $this->createWallet($member->household);
        $this->authenticate($member->user);
        $base = '/api/v1/households/'.$member->household_id.'/wallets';
        $this->getJson($base)->assertOk()->assertJsonPath('data.0.balance', '125000.25');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($base, ['name' => 'Wallet', 'type' => 'cash'])->assertForbidden();
        $this->patchJson($base.'/'.$result['data']['id'], ['is_archived' => true])->assertForbidden();
    }

    public function test_admin_can_create_and_archive_wallet(): void
    {
        $member = HouseholdMember::factory()->create(['role' => 'admin']);
        $this->authenticate($member->user);
        $base = '/api/v1/households/'.$member->household_id.'/wallets';
        $id = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($base, ['name' => 'Wallet', 'type' => 'cash'])->assertCreated()->json('data.id');
        $this->patchJson($base.'/'.$id, ['is_archived' => true])->assertOk();
    }

    public function test_cross_household_wallet_ids_and_revoked_members_are_hidden(): void
    {
        $household = Household::factory()->create();
        $foreign = Wallet::factory()->create();
        $this->authenticate($this->owner($household));
        $base = '/api/v1/households/'.$household->id.'/wallets';
        $this->getJson($base.'/'.$foreign->id)->assertNotFound();
        $this->getJson($base.'/'.$foreign->id.'/movements')->assertNotFound();
        $this->patchJson($base.'/'.$foreign->id, ['name' => 'Intrusion'])->assertNotFound();
        $member = HouseholdMember::factory()->create(['household_id' => $household->id, 'status' => 'removed']);
        $this->authenticate($member->user);
        $this->getJson($base)->assertNotFound();
    }

    public function test_archive_preserves_balance_history_and_can_be_reactivated(): void
    {
        $household = Household::factory()->create();
        $result = $this->createWallet($household);
        $this->authenticate($this->owner($household));
        $base = '/api/v1/households/'.$household->id.'/wallets';
        $id = $result['data']['id'];
        $this->patchJson($base.'/'.$id, ['name' => 'BCA Arsip', 'is_archived' => true])->assertOk()->assertJsonPath('data.balance', '125000.25');
        $this->getJson($base.'?status=active')->assertJsonCount(0, 'data');
        $this->getJson($base.'?status=archived&q=BCA')->assertJsonCount(1, 'data');
        $this->getJson($base.'/'.$id.'/movements?to=2026-01-01')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'/'.$id.'/movements?from=2026-02-01')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($base.'/'.$id, ['opening_amount' => '999.00'])->assertUnprocessable();
        $this->patchJson($base.'/'.$id, ['is_archived' => false])->assertOk();
        $this->assertDatabaseCount('balance_movements', 1);
        $this->assertDatabaseCount('operations', 1);
    }

    public function test_pagination_and_literal_search_are_bounded(): void
    {
        $household = Household::factory()->create();
        Wallet::factory()->create(['household_id' => $household->id, 'name' => '100% cash']);
        $this->authenticate($this->owner($household));
        $base = '/api/v1/households/'.$household->id.'/wallets';
        $this->getJson($base.'?q=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($base.'?q='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'?per_page=101')->assertUnprocessable();
    }

    public function test_failed_audit_rolls_back_wallet_projection_ledger_and_receipt(): void
    {
        $household = Household::factory()->create();
        $this->authenticate($this->owner($household));
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_wallet CHECK (action <> 'wallet.created')");
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/households/'.$household->id.'/wallets', ['name' => 'Wallet', 'type' => 'cash', 'opening_amount' => '100.00'])->assertStatus(500);
        foreach (['wallets', 'wallet_balances', 'operations', 'balance_movements', 'idempotency_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_ledger_is_immutable(string $action): void
    {
        $this->createWallet(Household::factory()->create());
        try {
            DB::transaction(function () use ($action): void {
                $query = DB::table('balance_movements');
                if ($action === 'update') {
                    $query->update(['amount_signed' => '1.00']);
                } else {
                    $query->delete();
                }
            });
            $this->fail('Ledger mutation must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
        $this->assertDatabaseHas('balance_movements', ['amount_signed' => '125000.25']);
    }

    public function test_database_rejects_cross_household_ledger_and_negative_projection(): void
    {
        $result = $this->createWallet(Household::factory()->create());
        $foreign = Wallet::factory()->create();
        $movement = (array) DB::table('balance_movements')->first();
        unset($movement['id']);
        $movement['wallet_id'] = $foreign->id;
        try {
            DB::transaction(fn () => DB::table('balance_movements')->insert($movement));
            $this->fail('Cross-household ledger must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => DB::table('wallet_balances')->where('wallet_id', $result['data']['id'])->update(['balance' => '-0.01']));
            $this->fail('Negative projection must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_reconciliation_detects_drift_and_missing_projection_without_repairing(): void
    {
        $household = Household::factory()->create();
        $result = $this->createWallet($household);
        DB::table('wallet_balances')->where('wallet_id', $result['data']['id'])->update(['balance' => '1.00']);
        $this->artisan('wallets:reconcile', ['--household' => $household->id])->assertFailed();
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $result['data']['id'], 'balance' => '1.00']);
        DB::table('wallet_balances')->where('wallet_id', $result['data']['id'])->delete();
        $this->artisan('wallets:reconcile')->assertFailed();
    }

    #[TestWith(['GET', '/wallets'])]
    #[TestWith(['POST', '/wallets'])]
    #[TestWith(['GET', '/wallets/1'])]
    #[TestWith(['PATCH', '/wallets/1'])]
    #[TestWith(['GET', '/wallets/1/movements'])]
    public function test_wallet_endpoints_require_auth(string $method, string $path): void
    {
        $this->json($method, '/api/v1/households/1'.$path)->assertUnauthorized();
    }
}
