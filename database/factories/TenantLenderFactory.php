<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantLender;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantLenderFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantLender::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'code' => fake()->unique()->bothify('PERFL########'),
            'update_key' => 0,
            'is_deleted' => false,
        ];
    }
}
