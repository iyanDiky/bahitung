<?php

namespace App\Services;

use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use App\Repositories\Contracts\HouseholdRepository;
use App\Repositories\Contracts\MembershipRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class MembershipService
{
    public function __construct(private HouseholdRepository $households, private MembershipRepository $members) {}

    public function invite(User $user, int $id, array $attributes): HouseholdInvitation
    {
        return DB::transaction(function () use ($user, $id, $attributes): HouseholdInvitation {
            $household = $this->households->find($id, lock: true);
            Gate::forUser($user)->authorize('manage-members', $household);
            abort_if($this->members->hasEmail($household, $attributes['email']), 409);
            $pending = $this->members->pending($household, $attributes['email']);
            if ($pending) {
                abort_if($pending->expires_at->isFuture(), 409);
                $this->members->saveInvitation($pending, ['status' => 'revoked', 'revoked_at' => now()]);
            }
            $actor = $this->households->membership($user, $household);
            $token = Str::random(64);
            $invitation = $this->members->createInvitation(['household_id' => $id, 'invited_email' => $attributes['email'], 'target_role' => $attributes['role'],
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'invited_by_member_id' => $actor->id]);
            $this->members->audit($household, $actor, 'invitation.created', null, ['invitation_id' => $invitation->id, 'role' => $invitation->target_role]);
            DB::afterCommit(fn () => Notification::route('mail', $invitation->invited_email)->notify(new HouseholdInvitationNotification($token)));

            return $invitation->refresh();
        });
    }

    public function invitations(User $user, int $id, int $perPage): LengthAwarePaginator
    {
        $household = $this->households->find($id);
        Gate::forUser($user)->authorize('manage-members', $household);

        return $this->members->invitations($household, $perPage);
    }

    public function revoke(User $user, int $id, int $invitationId): HouseholdInvitation
    {
        return DB::transaction(function () use ($user, $id, $invitationId): HouseholdInvitation {
            $household = $this->households->find($id, lock: true);
            Gate::forUser($user)->authorize('manage-members', $household);
            $invitation = $this->members->invitation($household, $invitationId);
            abort_if($invitation->status !== 'pending', 409);
            $this->members->saveInvitation($invitation, ['status' => 'revoked', 'revoked_at' => now()]);
            $this->members->audit($household, $this->households->membership($user, $household), 'invitation.revoked', null, ['invitation_id' => $invitation->id]);

            return $invitation;
        });
    }

    public function accept(User $user, string $token): HouseholdMember
    {
        abort_unless($user->hasVerifiedEmail(), 403);

        return DB::transaction(function () use ($user, $token): HouseholdMember {
            $reference = $this->members->byToken(hash('sha256', $token));
            $household = $this->households->find($reference->household_id, lock: true);
            $invitation = $this->members->invitation($household, $reference->id);
            abort_unless(hash_equals($invitation->invited_email, $user->email), 404);
            abort_if($invitation->status !== 'pending' || ! $invitation->expires_at->isFuture(), 409);
            abort_if($this->members->memberByUser($household, $user->id) !== null, 409);
            $member = $this->members->join($household, $user->id, $invitation->target_role);
            $this->members->saveInvitation($invitation, ['status' => 'accepted', 'accepted_by_user_id' => $user->id, 'accepted_at' => now()]);
            $this->members->audit($household, $member, 'invitation.accepted', null, ['invitation_id' => $invitation->id, 'member_id' => $member->id, 'role' => $member->role]);

            return $member;
        });
    }

    public function update(User $user, int $id, int $memberId, array $attributes): HouseholdMember
    {
        return DB::transaction(function () use ($user, $id, $memberId, $attributes): HouseholdMember {
            $household = $this->households->find($id, lock: true);
            Gate::forUser($user)->authorize('manage-members', $household);
            $member = $this->members->member($household, $memberId);
            Gate::forUser($user)->authorize('change-member', [$household, $member, $attributes['role'] ?? $member->role]);
            $before = $member->only(['id', 'role', 'status']);
            $changes = array_intersect_key($attributes, array_flip(['role', 'status']));
            if (isset($changes['status'])) {
                $changes['left_at'] = $changes['status'] === 'removed' ? now() : null;
            }
            $this->members->saveMember($member, $changes);
            $this->members->audit($household, $this->households->membership($user, $household), 'member.updated', $before, [...$member->only(['id', 'role', 'status']), 'reason' => $attributes['reason']]);

            return $member;
        });
    }

    public function transfer(User $user, int $id, array $attributes): HouseholdMember
    {
        return DB::transaction(function () use ($user, $id, $attributes): HouseholdMember {
            $household = $this->households->find($id, lock: true);
            Gate::forUser($user)->authorize('transfer-owner', $household);
            $actor = $this->households->membership($user, $household);
            $target = $this->members->member($household, $attributes['member_id']);
            abort_if($target->id === $actor->id || $target->status !== 'active', 422);
            $before = ['owner_member_id' => $actor->id, 'target_role' => $target->role];
            $this->members->saveMember($actor, ['role' => 'admin']);
            $this->members->saveMember($target, ['role' => 'owner']);
            $this->members->audit($household, $actor, 'ownership.transferred', $before, ['owner_member_id' => $target->id, 'previous_owner_role' => 'admin', 'reason' => $attributes['reason']]);

            return $target;
        });
    }
}
