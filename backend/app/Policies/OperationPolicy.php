<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Auth\Access\Response;

class OperationPolicy
{
    public function __construct(private HouseholdRepository $households, private WalletPolicy $wallets) {}

    public function create(User $user, Household $household): Response
    {
        $access = $this->wallets->viewAny($user, $household);
        if ($access->denied()) {
            return $access;
        }

        return in_array($this->households->membership($user, $household)->role, ['owner', 'admin', 'member'], true) ? Response::allow() : Response::deny();
    }

    public function correct(User $user, Household $household, array $operation): Response
    {
        $access = $this->create($user, $household);
        if ($access->denied()) {
            return $access;
        }
        $member = $this->households->membership($user, $household);
        if (in_array($member->role, ['owner', 'admin'], true)) {
            return Response::allow();
        }

        return in_array($operation['type'], ['income', 'expense', 'transfer'], true) && $operation['created_by_member_id'] === $member->id ? Response::allow() : Response::deny();
    }
}
