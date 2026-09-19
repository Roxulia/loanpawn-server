<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantCustomer;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantCustomerFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantCustomer::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFC########'),
            'name' => fake()->name(),
            'nrc' => fake()->unique()->bothify('PERF/C/########'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('09#########'),
            'address' => fake()->address(),
            'trust_score' => fake()->numberBetween(40, 220),
            'is_deleted' => false,
            'is_auto_generated' => false,
        ];
    }
}
