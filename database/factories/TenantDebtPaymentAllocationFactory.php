<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantDebtPaymentAllocation;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDebtPaymentAllocationFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantDebtPaymentAllocation::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return ['amount' => fake()->numberBetween(500, 50_000)];
    }
}
