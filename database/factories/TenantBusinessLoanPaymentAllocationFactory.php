<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantBusinessLoanPaymentAllocation;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantBusinessLoanPaymentAllocationFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantBusinessLoanPaymentAllocation::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return ['amount' => fake()->numberBetween(1_000, 100_000)];
    }
}
