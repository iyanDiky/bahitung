<?php

namespace App\Repositories\Contracts;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface HouseholdRepository
{
    public function listFor(User $user, int $perPage): LengthAwarePaginator;

    public function membershipsFor(User $user): array;

    public function find(int $id, bool $lock = false): Household;

    public function membership(User $user, Household $household): ?HouseholdMember;

    public function create(User $user, array $attributes): Household;

    public function update(Household $household, array $attributes): Household;

    public function members(Household $household, int $perPage): LengthAwarePaginator;

    public function audit(Household $household, HouseholdMember $actor, string $action, ?array $before): void;
}
