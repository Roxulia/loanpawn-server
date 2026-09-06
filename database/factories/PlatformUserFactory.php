<?php

namespace Database\Factories;

use App\Models\PlatformModule\PlatformUser;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformUserFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = PlatformUser::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFPU########'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('09#########'),
            'password' => config('performance-testing.password'),
            'status' => 'active',
            'email_verified_at' => now(),
        ];
    }
}
