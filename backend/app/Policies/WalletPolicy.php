<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Auth\Access\Response;

class WalletPolicy
{
    public function __construct(private HouseholdRepository $households) {}

    public function viewAny(User $user, Household $household): Response
    {
        return ! $household->archived_at && $this->households->membership($user, $household) ? Response::allow() : Response::denyAsNotFound();
    }

    public function manage(User $user, Household $household): Response
    {
        $access = $this->viewAny($user, $household);
        if ($access->denied()) {
            return $access;
        }

        return in_array($this->households->membership($user, $household)->role, ['owner', 'admin'], true) ? Response::allow() : Response::deny();
    }
}
