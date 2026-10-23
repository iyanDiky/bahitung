<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\HouseholdMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MembershipRepository
{
    public function member(Household $household, int $id): HouseholdMember;

    public function memberByUser(Household $household, int $user): ?HouseholdMember;

    public function hasEmail(Household $household, string $email): bool;

    public function pending(Household $household, string $email): ?HouseholdInvitation;

    public function createInvitation(array $attributes): HouseholdInvitation;

    public function invitation(Household $household, int $id): HouseholdInvitation;

    public function byToken(string $hash): HouseholdInvitation;

    public function invitations(Household $household, int $perPage): LengthAwarePaginator;

    public function saveInvitation(HouseholdInvitation $invitation, array $attributes): HouseholdInvitation;

    public function saveMember(HouseholdMember $member, array $attributes): HouseholdMember;

    public function join(Household $household, int $user, string $role): HouseholdMember;

    public function audit(Household $household, HouseholdMember $actor, string $action, ?array $before, array $after): void;
}
