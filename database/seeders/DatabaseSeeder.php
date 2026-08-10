<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            ExchangeRatePairSeeder::class,
            PackageSeeder::class,
            ExpenseTypeSeeder::class,
            MaterialTypeSeeder::class,
            ItemCategoryTypeSeeder::class,
            InterestTypeSeeder::class,
            TenantRoleSeeder::class,
            PlatformAccessSeeder::class,
        ]);
    }
}
