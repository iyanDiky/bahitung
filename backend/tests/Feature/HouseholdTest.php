<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\HouseholdService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    public function test_create_household_atomically_creates_one_owner_and_audit(): void
    {
        $user = User::factory()->create();
        $this->authenticate($user);
        $response = $this->postJson('/api/v1/households', ['name' => 'Keluarga Baru'])->assertCreated()
            ->assertJsonPath('data.currency_code', 'IDR')->assertJsonPath('data.timezone', 'Asia/Makassar');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('household_members', ['household_id' => $id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->assertDatabaseCount('household_members', 1);
        $this->assertDatabaseHas('audit_logs', ['household_id' => $id, 'action' => 'household.created']);
    }

    public function test_list_and_profile_only_include_active_memberships_and_active_households(): void
    {
        $user = User::factory()->create();
        $own = Household::factory()->create(['created_by_user_id' => $user->id]);
        $joined = HouseholdMember::factory()->create(['user_id' => $user->id, 'role' => 'viewer']);
        HouseholdMember::factory()->create(['user_id' => $user->id, 'status' => 'suspended']);
        Household::factory()->create(['created_by_user_id' => $user->id, 'archived_at' => now()]);
        Household::factory()->create();
        $this->authenticate($user);
        $this->getJson('/api/v1/households')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $own->id)->assertJsonPath('data.1.id', $joined->household_id);
        $this->getJson('/api/v1/me')->assertOk()->assertJsonCount(2, 'data.memberships')->assertJsonPath('data.memberships.1.role', 'viewer');
    }

    #[TestWith(['owner', true])]
    #[TestWith(['admin', false])]
    #[TestWith(['member', false])]
    #[TestWith(['viewer', false])]
    public function test_policy_matrix_is_scoped_to_household(string $role, bool $mayUpdate): void
    {
        $household = Household::factory()->create();
        $user = User::findOrFail($household->created_by_user_id);
        if ($role !== 'owner') {
            $member = HouseholdMember::factory()->create(['household_id' => $household->id, 'role' => $role]);
            $user = $member->user;
        }
        $other = Household::factory()->create();
        $this->assertTrue(Gate::forUser($user)->allows('view', $household));
        $this->assertSame($mayUpdate, Gate::forUser($user)->allows('update', $household));
        $this->assertSame(404, Gate::forUser($user)->inspect('view', $other)->status());
        $this->assertSame(404, Gate::forUser($user)->inspect('update', $other)->status());
    }

    public function test_owner_updates_settings_with_before_after_audit(): void
    {
        $household = Household::factory()->create(['name' => 'Nama Lama']);
        $this->authenticate(User::findOrFail($household->created_by_user_id));
        $this->patchJson('/api/v1/households/'.$household->id, ['name' => 'Nama Baru', 'timezone' => 'Asia/Jakarta'])->assertOk()->assertJsonPath('data.name', 'Nama Baru');
        $this->assertDatabaseHas('households', ['id' => $household->id, 'name' => 'Nama Baru', 'timezone' => 'Asia/Jakarta']);
        $audit = DB::table('audit_logs')->where('household_id', $household->id)->sole();
        $this->assertSame('Nama Lama', json_decode($audit->before, true)['name']);
        $this->assertSame('Nama Baru', json_decode($audit->after, true)['name']);
    }

    public function test_admin_cannot_change_household_settings(): void
    {
        $member = HouseholdMember::factory()->create(['role' => 'admin']);
        $this->authenticate($member->user);
        $this->patchJson('/api/v1/households/'.$member->household_id, ['name' => 'Forbidden'])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseMissing('households', ['name' => 'Forbidden']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['outsider'])]
    #[TestWith(['suspended'])]
    #[TestWith(['removed'])]
    public function test_inactive_or_foreign_members_cannot_read_or_update_household(string $status): void
    {
        $household = Household::factory()->create();
        $user = User::factory()->create();
        if ($status !== 'outsider') {
            HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'status' => $status]);
        }
        $this->authenticate($user);
        $this->getJson('/api/v1/households/'.$household->id)->assertNotFound()->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->getJson('/api/v1/households/'.$household->id.'/members')->assertNotFound();
        $this->patchJson('/api/v1/households/'.$household->id, ['name' => 'Forbidden'])->assertNotFound();
    }

    public function test_viewer_can_list_members_without_exposing_credentials_or_foreign_members(): void
    {
        $viewer = HouseholdMember::factory()->create(['role' => 'viewer']);
        $other = HouseholdMember::factory()->create();
        $this->authenticate($viewer->user);
        $this->getJson('/api/v1/households/'.$viewer->household_id.'/members')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.user.password')->assertJsonMissingPath('data.0.user.email')->assertJsonMissing(['household_id' => $other->household_id]);
    }

    public function test_membership_revocation_takes_effect_on_next_request(): void
    {
        $member = HouseholdMember::factory()->create();
        $this->authenticate($member->user);
        $this->getJson('/api/v1/households/'.$member->household_id)->assertOk();
        $member->update(['status' => 'removed']);
        $this->getJson('/api/v1/households/'.$member->household_id)->assertNotFound();
    }

    #[TestWith(['name', ''])]
    #[TestWith(['timezone', 'Invalid/Timezone'])]
    #[TestWith(['currency_code', 'USD'])]
    #[TestWith(['created_by_user_id', 999])]
    #[TestWith(['role', 'admin'])]
    public function test_invalid_household_payload_does_not_create_partial_records(string $field, mixed $value): void
    {
        $this->authenticate(User::factory()->create());
        $this->postJson('/api/v1/households', array_merge(['name' => 'Keluarga'], [$field => $value]))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [$field]]]);
        $this->assertDatabaseCount('households', 0);
        $this->assertDatabaseCount('household_members', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_failed_audit_rolls_back_household_and_owner(): void
    {
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_create CHECK (action <> 'household.created')");
        $this->authenticate(User::factory()->create());
        $this->postJson('/api/v1/households', ['name' => 'Must Roll Back'])->assertStatus(500);
        $this->assertDatabaseCount('households', 0);
        $this->assertDatabaseCount('household_members', 0);
    }

    public function test_database_prevents_a_second_active_owner(): void
    {
        $household = Household::factory()->create();
        $user = User::factory()->create();
        try {
            DB::transaction(fn () => HouseholdMember::factory()->create(['household_id' => $household->id, 'user_id' => $user->id, 'role' => 'owner']));
            $this->fail('A second active owner must be rejected.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame(1, $household->members()->where('role', 'owner')->where('status', 'active')->count());
        }
    }

    #[TestWith(['update'])]
    #[TestWith(['delete'])]
    public function test_audit_history_cannot_be_modified(string $action): void
    {
        $user = User::factory()->create();
        $household = app(HouseholdService::class)->create($user, ['name' => 'Original']);
        try {
            DB::transaction(function () use ($action, $household): void {
                $query = DB::table('audit_logs')->where('household_id', $household->id);
                if ($action === 'update') {
                    $query->update(['action' => 'tampered']);
                } else {
                    $query->delete();
                }
            });
            $this->fail('Audit history must be immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
            $this->assertDatabaseHas('audit_logs', ['household_id' => $household->id, 'action' => 'household.created']);
        }
    }

    public function test_pagination_is_bounded(): void
    {
        $this->authenticate(User::factory()->create());
        $this->getJson('/api/v1/households?per_page=101')->assertUnprocessable();
    }

    public function test_archived_household_cannot_be_read_by_owner(): void
    {
        $household = Household::factory()->create(['archived_at' => now()]);
        $this->authenticate(User::findOrFail($household->created_by_user_id));
        $this->getJson('/api/v1/households/'.$household->id)->assertNotFound();
    }
}
