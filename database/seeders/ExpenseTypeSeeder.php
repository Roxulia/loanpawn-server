<?php

namespace Database\Seeders;

use App\Models\CoreModule\ExpenseType;
use Illuminate\Database\Seeder;

class ExpenseTypeSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $expenseTypes = [
            ['code' => 'general', 'name' => 'General'],
            ['code' => 'rent', 'name' => 'Rent'],
            ['code' => 'salary', 'name' => 'Salary'],
            ['code' => 'utilities', 'name' => 'Utilities'],
            ['code' => 'maintainence', 'name' => 'Maintainence'],
        ];

        foreach ($expenseTypes as $expenseType) {
            ExpenseType::updateOrCreate(
                ['code' => $expenseType['code']],
                [
                    'tenant_id' => null,
                    'name' => $expenseType['name'],
                    'is_default' => true,
                ]
            );
        }
    }
}
