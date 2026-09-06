<?php

namespace Database\Factories;

use App\Models\PlatformModule\TenantLicense;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantLicenseFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantLicense::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'license_key' => fake()->unique()->uuid(),
            'plan_type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subYear(),
            'expires_at' => now()->addYears(5),
            'activated_at' => now()->subYear(),
        ];
    }
}
