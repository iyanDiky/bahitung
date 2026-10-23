<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Auth\Access\Response;

class MembershipPolicy
{
    public function __construct(private HouseholdRepository $households) {}

    public function manage(User $user, Household $household): Response
    {
        $member = $this->households->membership($user, $household);
        if (! $member || $household->archived_at) {
            return Response::denyAsNotFound();
        }

        return in_array($member->role, ['owner', 'admin'], true) ? Response::allow() : Response::deny();
    }

    public function change(User $user, Household $household, HouseholdMember $target, string $role): Response
    {
        $access = $this->manage($user, $household);
        if ($access->denied()) {
            return $access;
        }
        if ($target->household_id !== $household->id) {
            return Response::denyAsNotFound();
        }
        if ($target->role === 'owner' || $role === 'owner') {
            return Response::deny('Gunakan transfer kepemilikan.');
        }
        $actor = $this->households->membership($user, $household);

        return $actor->role === 'owner' || (in_array($target->role, ['member', 'viewer'], true) && in_array($role, ['member', 'viewer'], true)) ? Response::allow() : Response::deny();
    }

    public function transfer(User $user, Household $household): Response
    {
        $access = $this->manage($user, $household);
        if ($access->denied()) {
            return $access;
        }

        return $this->households->membership($user, $household)->role === 'owner' ? Response::allow() : Response::deny();
    }
}
