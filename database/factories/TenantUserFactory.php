<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantUser;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantUserFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantUser::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFTU########'),
            'username' => fake()->unique()->bothify('PT####'),
            'name' => fake()->name(),
            'nrc' => fake()->unique()->bothify('PERF/NRC/########'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('09#########'),
            'password' => config('performance-testing.password'),
            'status' => 'active',
            'is_deleted' => false,
        ];
    }
}
