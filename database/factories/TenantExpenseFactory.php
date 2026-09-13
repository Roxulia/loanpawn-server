<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantExpense;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantExpenseFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantExpense::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFEX########'),
            'description' => 'Performance-test expense',
            'amount' => fake()->numberBetween(10_000, 500_000),
            'image_reference' => null,
            'is_deleted' => false,
        ];
    }
}
