<?php

namespace Database\Seeders;

use App\Models\CoreModule\MaterialType;
use Illuminate\Database\Seeder;

class MaterialTypeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $materialTypes = [
            ['code' => 'gold', 'name' => 'Gold'],
            ['code' => 'silver', 'name' => 'Silver'],
            ['code' => 'platinum', 'name' => 'Platinum'],
        ];

        foreach ($materialTypes as $materialType) {
            MaterialType::updateOrCreate(
                ['code' => $materialType['code']],
                [
                    'tenant_id' => null,
                    'name' => $materialType['name'],
                    'is_default' => true,
                ]
            );
        }
    }
}
