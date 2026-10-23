<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class HouseholdInvitationFactory extends Factory
{
    protected $model = HouseholdInvitation::class;

    public function definition(): array
    {
        return ['household_id' => Household::factory(), 'invited_email' => fake()->unique()->safeEmail(), 'target_role' => 'member', 'token_hash' => hash('sha256', Str::random(64)), 'status' => 'pending', 'expires_at' => now()->addDays(7),
            'invited_by_member_id' => fn (array $attributes) => Household::findOrFail($attributes['household_id'])->members()->where('role', 'owner')->sole()->id];
    }
}
