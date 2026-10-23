<?php

namespace App\Repositories;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Repositories\Contracts\HouseholdRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentHouseholdRepository implements HouseholdRepository
{
    public function listFor(User $user, int $perPage): LengthAwarePaginator
    {
        return Household::whereNull('archived_at')->whereHas('members', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->with(['members' => fn ($query) => $query->where('user_id', $user->id)->where('status', 'active')])->orderBy('id')->paginate($perPage);
    }

    public function membershipsFor(User $user): array
    {
        return HouseholdMember::where('user_id', $user->id)->where('status', 'active')->whereHas('household', fn ($query) => $query->whereNull('archived_at'))
            ->with('household')->orderBy('household_id')->get()->map(fn (HouseholdMember $member) => [
                'id' => $member->id, 'household_id' => $member->household_id, 'role' => $member->role, 'status' => $member->status, 'household' => $member->household,
            ])->all();
    }

    public function find(int $id, bool $lock = false): Household
    {
        return Household::whereNull('archived_at')->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    public function membership(User $user, Household $household): ?HouseholdMember
    {
        return $household->members()->where('user_id', $user->id)->where('status', 'active')->first();
    }

    public function create(User $user, array $attributes): Household
    {
        $household = Household::create([...$attributes, 'created_by_user_id' => $user->id]);
        $household->members()->create(['user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);

        return $household->refresh();
    }

    public function update(Household $household, array $attributes): Household
    {
        $household->update($attributes);

        return $household->refresh();
    }

    public function members(Household $household, int $perPage): LengthAwarePaginator
    {
        return $household->members()->with('user:id,name')->orderBy('id')->paginate($perPage);
    }

    public function audit(Household $household, HouseholdMember $actor, string $action, ?array $before): void
    {
        DB::table('audit_logs')->insert(['household_id' => $household->id, 'actor_member_id' => $actor->id, 'action' => $action,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($household->only(['name', 'timezone', 'currency_code']), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
