<?php

namespace App\Services;

use App\Models\Household;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class HouseholdService
{
    public function __construct(private HouseholdRepository $households) {}

    public function list(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->households->listFor($user, $perPage);
    }

    public function memberships(User $user): array
    {
        return $this->households->membershipsFor($user);
    }

    public function show(User $user, int $id): Household
    {
        $household = $this->households->find($id);
        Gate::forUser($user)->authorize('view', $household);

        return $household;
    }

    public function create(User $user, array $attributes): Household
    {
        return DB::transaction(function () use ($user, $attributes): Household {
            $household = $this->households->create($user, $attributes);
            $this->households->audit($household, $this->households->membership($user, $household), 'household.created', null);

            return $household;
        });
    }

    public function update(User $user, int $id, array $attributes): Household
    {
        return DB::transaction(function () use ($user, $id, $attributes): Household {
            $household = $this->households->find($id, lock: true);
            Gate::forUser($user)->authorize('update', $household);
            $before = $household->only(['name', 'timezone', 'currency_code']);
            $household = $this->households->update($household, $attributes);
            $this->households->audit($household, $this->households->membership($user, $household), 'household.updated', $before);

            return $household;
        });
    }

    public function members(User $user, int $id, int $perPage): LengthAwarePaginator
    {
        return $this->households->members($this->show($user, $id), $perPage);
    }
}
