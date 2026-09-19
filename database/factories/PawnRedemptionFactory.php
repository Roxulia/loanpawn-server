<?php

namespace Database\Factories;

use App\Models\PawnModule\PawnRedemption;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PawnRedemptionFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = PawnRedemption::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $principal = fake()->numberBetween(50_000, 5_000_000);
        $interest = fake()->numberBetween(1_000, 100_000);

        return [
            'slip_number' => fake()->unique()->bothify('PERF-RD-########'),
            'gross_amount' => $principal + $interest,
            'net_amount' => $principal + $interest,
            'interest_amount' => $interest,
            'received_amount' => $principal + $interest,
            'change_amount' => 0,
            'redemption_at' => now(),
            'notes' => 'Generated performance-test redemption',
        ];
    }
}
