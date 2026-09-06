<?php

namespace Database\Factories;

use App\Models\TenantAccountingTransactions;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantAccountingTransactionFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantAccountingTransactions::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $occurredAt = fake()->dateTimeBetween('-2 years', 'now');

        return [
            'business_date' => $occurredAt->format('Y-m-d'),
            'transaction_direction' => 'incoming',
            'accounting_category' => 'revenue',
            'amount' => fake()->numberBetween(1_000, 5_000_000),
            'reporting_amount' => null,
            'exchange_rate' => null,
            'description' => 'Generated performance-test transaction',
            'occurred_at' => $occurredAt,
            'is_deleted' => false,
        ];
    }
}
