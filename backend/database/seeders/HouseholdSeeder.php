<?php

namespace Database\Seeders;

use App\Models\Household;
use Illuminate\Database\Seeder;

class HouseholdSeeder extends Seeder
{
    public function run(): void
    {
        Household::factory()->create();
    }
}
