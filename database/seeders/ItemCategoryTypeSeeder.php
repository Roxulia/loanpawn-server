<?php

namespace Database\Seeders;

use App\Models\CoreModule\ItemCategoryType;
use Illuminate\Database\Seeder;

class ItemCategoryTypeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $itemCategoryTypes = [
            ['code' => 'watches', 'name' => 'Watches'],
            ['code' => 'real_estate', 'name' => 'Real Estate'],
            ['code' => 'car', 'name' => 'Car'],
        ];

        foreach ($itemCategoryTypes as $itemCategoryType) {
            ItemCategoryType::updateOrCreate(
                ['code' => $itemCategoryType['code']],
                [
                    'tenant_id' => null,
                    'name' => $itemCategoryType['name'],
                    'is_default' => true,
                ]
            );
        }
    }
}
