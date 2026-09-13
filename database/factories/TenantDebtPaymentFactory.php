<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantDebtPayment;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDebtPaymentFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantDebtPayment::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $amount = fake()->numberBetween(1_000, 100_000);

        return [
            'code' => fake()->unique()->bothify('PERFDP########'),
            'update_key' => 0,
            'is_deleted' => false,
            'allocation_order' => 'interest_first',
            'payment_amount' => $amount,
            'principal_paid' => $amount,
            'interest_paid' => 0,
            'change_amount' => 0,
            'payment_at' => now()->subDays(2),
        ];
    }
}
