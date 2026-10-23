<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use App\Repositories\Contracts\MembershipRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentMembershipRepository implements MembershipRepository
{
    public function member(Household $household, int $id): HouseholdMember
    {
        return $household->members()->findOrFail($id);
    }

    public function memberByUser(Household $household, int $user): ?HouseholdMember
    {
        return $household->members()->where('user_id', $user)->first();
    }

    public function hasEmail(Household $household, string $email): bool
    {
        return $household->members()->whereHas('user', fn ($q) => $q->where('email', $email))->exists();
    }

    public function pending(Household $household, string $email): ?HouseholdInvitation
    {
        return HouseholdInvitation::where('household_id', $household->id)->where('invited_email', $email)->where('status', 'pending')->first();
    }

    public function createInvitation(array $attributes): HouseholdInvitation
    {
        return HouseholdInvitation::create($attributes);
    }

    public function invitation(Household $household, int $id): HouseholdInvitation
    {
        return HouseholdInvitation::where('household_id', $household->id)->findOrFail($id);
    }

    public function byToken(string $hash): HouseholdInvitation
    {
        return HouseholdInvitation::where('token_hash', $hash)->firstOrFail();
    }

    public function invitations(Household $household, int $perPage): LengthAwarePaginator
    {
        return HouseholdInvitation::where('household_id', $household->id)->orderByDesc('id')->paginate($perPage);
    }

    public function saveInvitation(HouseholdInvitation $invitation, array $attributes): HouseholdInvitation
    {
        $invitation->fill($attributes)->save();

        return $invitation->refresh();
    }

    public function saveMember(HouseholdMember $member, array $attributes): HouseholdMember
    {
        $member->fill($attributes)->save();

        return $member->refresh();
    }

    public function join(Household $household, int $user, string $role): HouseholdMember
    {
        return $household->members()->create(['user_id' => $user, 'role' => $role, 'status' => 'active', 'joined_at' => now()]);
    }

    public function audit(Household $household, HouseholdMember $actor, string $action, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert(['household_id' => $household->id, 'actor_member_id' => $actor->id, 'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
