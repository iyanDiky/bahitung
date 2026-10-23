<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CategoryService
{
    public function __construct(private HouseholdRepository $households, private FinanceRepository $finance) {}

    public function list(User $user, int $householdId, array $filters): LengthAwarePaginator
    {
        $household = $this->households->find($householdId);
        Gate::forUser($user)->authorize('view-categories', $household);

        return $this->finance->categories($householdId, $filters);
    }

    public function save(User $user, int $householdId, array $attributes, ?int $id = null): object
    {
        return DB::transaction(function () use ($user, $householdId, $attributes, $id): object {
            $household = $this->households->find($householdId, lock: true);
            Gate::forUser($user)->authorize('manage-categories', $household);
            $before = $id === null ? null : (array) $this->finance->category($householdId, $id);
            $attributes = array_intersect_key($attributes, array_flip($id === null ? ['name', 'type'] : ['name', 'is_archived']));
            $name = $attributes['name'] ?? $before['name'];
            $type = $attributes['type'] ?? $before['type'];
            abort_if($this->finance->categoryNameExists($householdId, $type, $name, $id), 409);
            $result = $this->finance->saveCategory($householdId, $attributes, $id);
            $this->finance->audit($householdId, $this->households->membership($user, $household)->id, $id === null ? 'category.created' : 'category.updated', $before, (array) $result);

            return $result;
        }, attempts: 3);
    }
}
