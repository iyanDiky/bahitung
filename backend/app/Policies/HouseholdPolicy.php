<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Auth\Access\Response;

class HouseholdPolicy
{
    public function __construct(private HouseholdRepository $households) {}

    public function view(User $user, Household $household): Response
    {
        return ! $household->archived_at && $this->households->membership($user, $household)
            ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Household $household): Response
    {
        $access = $this->view($user, $household);
        if ($access->denied()) {
            return $access;
        }

        return $this->households->membership($user, $household)->role === 'owner'
            ? Response::allow() : Response::deny('Hanya Owner yang dapat mengubah pengaturan keluarga.');
    }
}
