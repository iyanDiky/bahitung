<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return ['household_id' => Household::factory(), 'created_by_member_id' => fn (array $attributes) => Household::findOrFail($attributes['household_id'])->members()->where('role', 'owner')->sole()->id, 'name' => fake()->word(), 'type' => 'cash', 'is_archived' => false];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Wallet $wallet) => DB::table('wallet_balances')->insert(['wallet_id' => $wallet->id, 'household_id' => $wallet->household_id, 'balance' => '0.00', 'updated_at' => now()]));
    }
}
