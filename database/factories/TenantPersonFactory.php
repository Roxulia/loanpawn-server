<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantPerson;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantPersonFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantPerson::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'update_key' => 0,
            'is_deleted' => false,
            'name' => fake()->name(),
            'nrc' => fake()->unique()->bothify('PERF/P/########'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('09#########'),
            'address' => fake()->address(),
            'note' => 'Performance-test person',
        ];
    }
}
