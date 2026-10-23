<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\FinanceRepository;
use App\Repositories\Contracts\GoalRepository;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GoalService
{
    public function __construct(private HouseholdRepository $households, private GoalRepository $goals, private FinanceRepository $finance) {}

    public function list(User $user, int $household, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('view-goals', $this->households->find($household));

        return $this->goals->list($household, $filters);
    }

    public function show(User $user, int $household, int $id): array
    {
        Gate::forUser($user)->authorize('view-goals', $this->households->find($household));

        return $this->goals->find($household, $id);
    }

    public function movements(User $user, int $household, int $id, array $filters): LengthAwarePaginator
    {
        $this->show($user, $household, $id);

        return $this->goals->movements($household, $id, $filters);
    }

    public function save(User $user, int $household, array $attributes, ?int $id = null): array
    {
        return DB::transaction(function () use ($user, $household, $attributes, $id): array {
            $family = $this->households->find($household, lock: true);
            Gate::forUser($user)->authorize('manage-goals', $family);
            $before = $id === null ? null : $this->goals->find($household, $id);
            $actor = $this->households->membership($user, $family)->id;
            $attributes = array_intersect_key($attributes, array_flip($id === null ? ['name', 'target_amount', 'target_date'] : ['name', 'target_amount', 'target_date', 'is_archived']));
            $goal = $this->goals->save($household, $actor, $attributes, $id);
            $this->finance->audit($household, $actor, $id === null ? 'goal.created' : 'goal.updated', $before, $goal);

            return $goal;
        }, attempts: 3);
    }
}
