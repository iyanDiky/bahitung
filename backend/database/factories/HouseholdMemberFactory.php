<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HouseholdMemberFactory extends Factory
{
    protected $model = HouseholdMember::class;

    public function definition(): array
    {
        return ['household_id' => Household::factory(), 'user_id' => User::factory(), 'role' => 'member', 'status' => 'active', 'joined_at' => now()];
    }
}
