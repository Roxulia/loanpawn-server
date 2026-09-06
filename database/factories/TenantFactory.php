<?php

namespace Database\Factories;

use App\Models\PlatformModule\Tenant;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = Tenant::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'name' => fake()->company().' Performance',
            'tenant_code' => fake()->unique()->bothify('perf-tenant-###'),
            'subdomain' => fake()->unique()->bothify('perf-###'),
            'plan_type' => 'premium',
            'status' => 'active',
        ];
    }
}
