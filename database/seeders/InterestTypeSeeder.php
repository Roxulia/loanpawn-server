<?php

namespace Database\Seeders;

use App\Models\CoreModule\InterestType;
use Illuminate\Database\Seeder;

class InterestTypeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $interestTypes = [
            ['code' => 'daily', 'name' => 'Daily', 'duration_in_days' => 1],
            ['code' => 'weekly', 'name' => 'Weekly', 'duration_in_days' => 7],
            ['code' => 'monthly', 'name' => 'Monthly', 'duration_in_days' => 30],
        ];

        foreach ($interestTypes as $interestType) {
            InterestType::updateOrCreate(
                ['code' => $interestType['code']],
                [
                    'tenant_id' => null,
                    'name' => $interestType['name'],
                    'duration_in_days' => $interestType['duration_in_days'],
                    'is_default' => true,
                ]
            );
        }
    }
}
