<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantDebt;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDebtFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantDebt::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFD########'),
            'amount' => fake()->numberBetween(1_000, 100_000),
            'description' => 'Performance-test partial interest balance',
            'tag' => 'InterestPayment',
            'is_paid' => false,
            'apply_interest' => false,
        ];
    }
}
