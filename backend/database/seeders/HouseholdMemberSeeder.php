<?php

namespace Database\Seeders;

use App\Models\HouseholdMember;
use Illuminate\Database\Seeder;

class HouseholdMemberSeeder extends Seeder
{
    public function run(): void
    {
        HouseholdMember::factory()->create();
    }
}
