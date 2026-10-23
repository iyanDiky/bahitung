<?php

namespace Database\Seeders;

use App\Models\HouseholdInvitation;
use Illuminate\Database\Seeder;

class HouseholdInvitationSeeder extends Seeder
{
    public function run(): void
    {
        HouseholdInvitation::factory()->create();
    }
}
