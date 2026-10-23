<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MembershipManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function token(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function owner(Household $household): User
    {
        return User::findOrFail($household->created_by_user_id);
    }

    public function test_invite_sends_single_use_secret_only_by_notification(): void
    {
        Notification::fake();
        $household = Household::factory()->create();
        $this->token($this->owner($household));
        $response = $this->postJson('/api/v1/households/'.$household->id.'/invitations', ['email' => 'JOIN@example.test', 'role' => 'viewer'])->assertCreated()->assertJsonMissingPath('data.token_hash')->assertJsonMissingPath('data.token');
        $invitation = HouseholdInvitation::findOrFail($response->json('data.id'));
        $this->assertSame('join@example.test', $invitation->invited_email);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subSeconds(5), now()->addDays(7)->addSeconds(5)));
        Notification::assertSentOnDemand(HouseholdInvitationNotification::class, function ($notification, $channels, $notifiable) use ($invitation): bool {
            $this->assertSame($invitation->token_hash, hash('sha256', $notification->token));
            $this->assertSame('join@example.test', $notifiable->routes['mail']);
            $this->assertStringNotContainsString($notification->token, (string) DB::table('audit_logs')->value('after'));

            return true;
        });
        $this->getJson('/api/v1/households/'.$household->id.'/invitations')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.token_hash');
    }

    public function test_verified_recipient_accepts_once_with_server_assigned_role(): void
    {
        $user = User::factory()->create();
        $secret = str_repeat('a', 64);
        $invitation = HouseholdInvitation::factory()->create(['invited_email' => $user->email, 'target_role' => 'viewer', 'token_hash' => hash('sha256', $secret)]);
        $this->token($user);
        $this->postJson('/api/v1/invitations/accept', ['token' => $secret, 'role' => 'owner'])->assertCreated()->assertJsonPath('data.role', 'viewer');
        $this->assertDatabaseHas('household_invitations', ['id' => $invitation->id, 'status' => 'accepted', 'accepted_by_user_id' => $user->id]);
        $this->postJson('/api/v1/invitations/accept', ['token' => $secret])->assertConflict();
        $this->assertSame(1, HouseholdMember::where('household_id', $invitation->household_id)->where('user_id', $user->id)->count());
    }

    #[TestWith(['unverified', 403])]
    #[TestWith(['wrong-email', 404])]
    #[TestWith(['expired', 409])]
    #[TestWith(['revoked', 409])]
    #[TestWith(['archived', 404])]
    #[TestWith(['existing', 409])]
    public function test_accept_rejects_invalid_context(string $case, int $status): void
    {
        $user = User::factory()->create(['email_verified_at' => $case === 'unverified' ? null : now()]);
        $secret = str_repeat('b', 64);
        $invitation = HouseholdInvitation::factory()->create(['invited_email' => $case === 'wrong-email' ? 'different@example.test' : $user->email, 'token_hash' => hash('sha256', $secret), 'expires_at' => $case === 'expired' ? now()->subSecond() : now()->addDay(), 'status' => $case === 'revoked' ? 'revoked' : 'pending']);
        if ($case === 'archived') {
            Household::findOrFail($invitation->household_id)->forceFill(['archived_at' => now()])->save();
        }
        if ($case === 'existing') {
            HouseholdMember::factory()->create(['household_id' => $invitation->household_id, 'user_id' => $user->id, 'status' => 'suspended']);
        }
        $this->token($user);
        $this->postJson('/api/v1/invitations/accept', ['token' => $secret])->assertStatus($status);
        $this->assertNull($invitation->fresh()->accepted_at);
    }

    public function test_revoke_is_scoped_and_blocks_acceptance(): void
    {
        $recipient = User::factory()->create();
        $secret = str_repeat('c', 64);
        $invitation = HouseholdInvitation::factory()->create(['invited_email' => $recipient->email, 'token_hash' => hash('sha256', $secret)]);
        $household = Household::findOrFail($invitation->household_id);
        $foreign = HouseholdInvitation::factory()->create();
        $this->token($this->owner($household));
        $this->postJson('/api/v1/households/'.$household->id.'/invitations/'.$foreign->id.'/revoke')->assertNotFound();
        $this->postJson('/api/v1/households/'.$household->id.'/invitations/'.$invitation->id.'/revoke')->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->token($recipient);
        $this->postJson('/api/v1/invitations/accept', ['token' => $secret])->assertConflict();
    }

    #[TestWith(['member'])]
    #[TestWith(['viewer'])]
    public function test_non_managers_cannot_invite_list_or_update(string $role): void
    {
        $member = HouseholdMember::factory()->create(['role' => $role]);
        $this->token($member->user);
        $base = '/api/v1/households/'.$member->household_id;
        $this->postJson($base.'/invitations', ['email' => 'invite@example.test', 'role' => 'member'])->assertForbidden();
        $this->getJson($base.'/invitations')->assertForbidden();
        $this->patchJson($base.'/members/'.$member->id, ['role' => 'admin', 'reason' => 'Escalation'])->assertForbidden();
    }

    #[TestWith(['owner', 'admin', 'member', true])]
    #[TestWith(['owner', 'member', 'admin', true])]
    #[TestWith(['owner', 'owner', 'member', false])]
    #[TestWith(['admin', 'member', 'viewer', true])]
    #[TestWith(['admin', 'viewer', 'member', true])]
    #[TestWith(['admin', 'member', 'admin', false])]
    #[TestWith(['admin', 'admin', 'member', false])]
    #[TestWith(['admin', 'owner', 'member', false])]
    #[TestWith(['member', 'viewer', 'member', false])]
    #[TestWith(['viewer', 'member', 'viewer', false])]
    public function test_role_policy_matrix(string $actorRole, string $targetRole, string $newRole, bool $allowed): void
    {
        $household = Household::factory()->create();
        $owner = $household->members()->where('role', 'owner')->sole();
        $actor = $actorRole === 'owner' ? $owner : HouseholdMember::factory()->create(['household_id' => $household->id, 'role' => $actorRole]);
        $target = $targetRole === 'owner' ? $owner : HouseholdMember::factory()->create(['household_id' => $household->id, 'role' => $targetRole]);
        $this->assertSame($allowed, Gate::forUser($actor->user)->allows('change-member', [$household, $target, $newRole]));
    }

    public function test_admin_changes_member_but_cannot_promote_admin_or_remove_owner(): void
    {
        $admin = HouseholdMember::factory()->create(['role' => 'admin']);
        $member = HouseholdMember::factory()->create(['household_id' => $admin->household_id]);
        $this->token($admin->user);
        $base = '/api/v1/households/'.$admin->household_id.'/members/';
        $this->patchJson($base.$member->id, ['role' => 'viewer', 'reason' => 'Read only'])->assertOk();
        $this->assertDatabaseHas('household_members', ['id' => $member->id, 'role' => 'viewer']);
        $this->patchJson($base.$member->id, ['role' => 'admin', 'reason' => 'Not allowed'])->assertForbidden();
        $owner = $admin->household->members()->where('role', 'owner')->sole();
        $this->patchJson($base.$owner->id, ['status' => 'removed', 'reason' => 'Not allowed'])->assertForbidden();
    }

    public function test_removal_is_soft_and_access_changes_immediately(): void
    {
        $member = HouseholdMember::factory()->create();
        $this->token($this->owner($member->household));
        $this->patchJson('/api/v1/households/'.$member->household_id.'/members/'.$member->id, ['status' => 'removed', 'reason' => 'Keluar keluarga'])->assertOk();
        $this->assertDatabaseHas('household_members', ['id' => $member->id, 'status' => 'removed']);
        $this->assertNotNull($member->fresh()->left_at);
        $this->token($member->user);
        $this->getJson('/api/v1/households/'.$member->household_id)->assertNotFound();
    }

    public function test_ownership_transfer_is_atomic_and_old_owner_becomes_admin(): void
    {
        $target = HouseholdMember::factory()->create(['role' => 'viewer']);
        $household = $target->household;
        $this->token($this->owner($household));
        $this->postJson('/api/v1/households/'.$household->id.'/ownership-transfer', ['member_id' => $target->id, 'reason' => 'Pergantian pengelola'])->assertOk()->assertJsonPath('data.role', 'owner');
        $this->assertSame(1, $household->members()->where('role', 'owner')->where('status', 'active')->count());
        $this->assertDatabaseHas('household_members', ['household_id' => $household->id, 'user_id' => $household->created_by_user_id, 'role' => 'admin']);
        $this->postJson('/api/v1/households/'.$household->id.'/ownership-transfer', ['member_id' => $target->id, 'reason' => 'Replay'])->assertForbidden();
        $this->token($target->user);
        $this->patchJson('/api/v1/households/'.$household->id, ['name' => 'Owner Baru'])->assertOk();
    }

    #[TestWith(['self', 422])]
    #[TestWith(['inactive', 422])]
    #[TestWith(['foreign', 404])]
    public function test_transfer_rejects_invalid_target(string $case, int $status): void
    {
        $household = Household::factory()->create();
        $target = match ($case) {
            'self' => $household->members()->where('role', 'owner')->sole(),
            'inactive' => HouseholdMember::factory()->create(['household_id' => $household->id, 'status' => 'suspended']),
            default => HouseholdMember::factory()->create(),
        };
        $this->token($this->owner($household));
        $this->postJson('/api/v1/households/'.$household->id.'/ownership-transfer', ['member_id' => $target->id, 'reason' => 'Invalid'])->assertStatus($status);
        $this->assertDatabaseHas('household_members', ['household_id' => $household->id, 'user_id' => $household->created_by_user_id, 'role' => 'owner']);
    }

    public function test_audit_failure_rolls_back_ownership_transfer(): void
    {
        $target = HouseholdMember::factory()->create();
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_fail_transfer CHECK (action <> 'ownership.transferred')");
        $this->token($this->owner($target->household));
        $this->postJson('/api/v1/households/'.$target->household_id.'/ownership-transfer', ['member_id' => $target->id, 'reason' => 'Rollback'])->assertStatus(500);
        $this->assertSame('member', $target->fresh()->role);
        $this->assertDatabaseHas('household_members', ['household_id' => $target->household_id, 'user_id' => $target->household->created_by_user_id, 'role' => 'owner']);
    }

    public function test_foreign_and_inactive_managers_cannot_manage_memberships(): void
    {
        $admin = HouseholdMember::factory()->create(['role' => 'admin', 'status' => 'suspended']);
        $other = HouseholdMember::factory()->create();
        $this->token($admin->user);
        foreach ([$admin->household_id, $other->household_id] as $id) {
            $this->getJson('/api/v1/households/'.$id.'/invitations')->assertNotFound();
            $this->postJson('/api/v1/households/'.$id.'/invitations', ['email' => 'new@example.test', 'role' => 'member'])->assertNotFound();
        }
        $this->token($this->owner($admin->household));
        $this->patchJson('/api/v1/households/'.$admin->household_id.'/members/'.$other->id, ['role' => 'viewer', 'reason' => 'Foreign target'])->assertNotFound();
    }

    public function test_owner_can_restore_member_and_promote_admin_without_changing_identity(): void
    {
        $member = HouseholdMember::factory()->create(['status' => 'removed', 'left_at' => now()]);
        $this->token($this->owner($member->household));
        $this->patchJson('/api/v1/households/'.$member->household_id.'/members/'.$member->id, ['role' => 'admin', 'status' => 'active', 'reason' => 'Kembali', 'user_id' => 999, 'household_id' => 999])->assertOk();
        $this->assertDatabaseHas('household_members', ['id' => $member->id, 'user_id' => $member->user_id, 'household_id' => $member->household_id, 'role' => 'admin', 'status' => 'active', 'left_at' => null]);
        $this->token($member->user);
        $this->getJson('/api/v1/households/'.$member->household_id.'/invitations')->assertOk();
    }

    public function test_owner_cannot_remove_self_and_reason_is_required(): void
    {
        $household = Household::factory()->create();
        $owner = $household->members()->where('role', 'owner')->sole();
        $this->token($owner->user);
        $base = '/api/v1/households/'.$household->id.'/members/'.$owner->id;
        $this->patchJson($base, ['status' => 'removed', 'reason' => 'Invalid'])->assertForbidden();
        $this->patchJson($base, ['status' => 'removed'])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['reason']]]);
    }

    public function test_accept_audit_failure_preserves_pending_invitation_and_creates_no_member(): void
    {
        $user = User::factory()->create();
        $secret = str_repeat('d', 64);
        $invite = HouseholdInvitation::factory()->create(['invited_email' => $user->email, 'token_hash' => hash('sha256', $secret)]);
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_fail_accept CHECK (action <> 'invitation.accepted')");
        $this->token($user);
        $this->postJson('/api/v1/invitations/accept', ['token' => $secret])->assertStatus(500);
        $this->assertSame('pending', $invite->fresh()->status);
        $this->assertDatabaseMissing('household_members', ['household_id' => $invite->household_id, 'user_id' => $user->id]);
    }

    public function test_failed_invitation_audit_does_not_send_notification(): void
    {
        Notification::fake();
        $household = Household::factory()->create();
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT test_fail_invite CHECK (action <> 'invitation.created')");
        $this->token($this->owner($household));
        $this->postJson('/api/v1/households/'.$household->id.'/invitations', ['email' => 'new@example.test', 'role' => 'member'])->assertStatus(500);
        $this->assertDatabaseCount('household_invitations', 0);
        Notification::assertNothingSent();
    }

    #[TestWith(['POST', '/api/v1/invitations/accept'])]
    #[TestWith(['POST', '/api/v1/households/1/invitations'])]
    #[TestWith(['GET', '/api/v1/households/1/invitations'])]
    #[TestWith(['POST', '/api/v1/households/1/invitations/1/revoke'])]
    #[TestWith(['PATCH', '/api/v1/households/1/members/1'])]
    #[TestWith(['POST', '/api/v1/households/1/ownership-transfer'])]
    #[TestWith(['POST', '/api/v1/auth/email/verification-notification'])]
    #[TestWith(['GET', '/api/v1/auth/email/verify/1/hash'])]
    public function test_new_protected_endpoints_require_auth(string $method, string $path): void
    {
        $this->json($method, $path)->assertUnauthorized();
    }

    public function test_duplicate_invites_and_invalid_roles_are_rejected(): void
    {
        Notification::fake();
        $invite = HouseholdInvitation::factory()->create();
        $household = Household::findOrFail($invite->household_id);
        $this->token($this->owner($household));
        $base = '/api/v1/households/'.$household->id.'/invitations';
        $this->postJson($base, ['email' => $invite->invited_email, 'role' => 'viewer'])->assertConflict();
        $this->postJson($base, ['email' => 'new@example.test', 'role' => 'admin'])->assertUnprocessable();
        $invite->update(['expires_at' => now()->subSecond()]);
        $this->postJson($base, ['email' => $invite->invited_email, 'role' => 'viewer'])->assertCreated();
        $this->assertSame('revoked', $invite->fresh()->status);
        Notification::assertSentOnDemand(HouseholdInvitationNotification::class);
    }
}
