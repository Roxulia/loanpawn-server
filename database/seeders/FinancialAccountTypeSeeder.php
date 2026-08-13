<?php

namespace Database\Seeders;

use App\Models\FinancialAccountTypes;
use Illuminate\Database\Seeder;

class FinancialAccountTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'bank', 'name' => 'Bank'],
            ['code' => 'cash', 'name' => 'Cash'],
            ['code' => 'online_pay', 'name' => 'Online Pay'],
        ];

        foreach ($types as $type) {
            FinancialAccountTypes::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $type['code']],
                ['name' => $type['name'], 'is_active' => true, 'update_key' => 0]
            );
        }
    }
}
