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
            // Generate a unique 16-character license key that matches the database column limit.
            'license_key' => fake()->unique()->regexify('[A-Z0-9]{16}'),
            'plan_type' => 'premium',
            'status' => 'active',
            'starts_at' => now()->subYear(),
            'expires_at' => now()->addYears(5),
            'activated_at' => now()->subYear(),
        ];
    }
}
