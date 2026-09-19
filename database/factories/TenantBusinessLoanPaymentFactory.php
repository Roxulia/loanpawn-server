<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantBusinessLoanPayment;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantBusinessLoanPaymentFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantBusinessLoanPayment::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $amount = fake()->numberBetween(10_000, 1_000_000);

        return [
            'code' => fake()->unique()->bothify('PERFBLP########'),
            'allocation_order' => 'interest_first',
            'payment_amount' => $amount,
            'principal_paid' => $amount,
            'interest_paid' => 0,
            'payment_at' => now()->subDays(3),
        ];
    }
}
