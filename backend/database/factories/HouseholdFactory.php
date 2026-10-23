<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class HouseholdFactory extends Factory
{
    protected $model = Household::class;

    public function definition(): array
    {
        return ['name' => 'Keluarga '.fake()->lastName(), 'timezone' => 'Asia/Makassar', 'created_by_user_id' => User::factory()];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Household $household): void {
            $household->members()->create(['user_id' => $household->created_by_user_id, 'role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        });
    }
}
