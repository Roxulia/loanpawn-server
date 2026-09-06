<?php

namespace Database\Factories;

use App\Models\PawnModule\PawnInterestPayment;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PawnInterestPaymentFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = PawnInterestPayment::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $start = now()->startOfDay();

        return [
            'payment_amount' => 0,
            'change_amount' => 0,
            'calculated_interest' => fake()->numberBetween(1_000, 100_000),
            'payment_at' => null,
            'start_period_at' => $start,
            'end_period_at' => $start->copy()->addMonth(),
            'period_timezone' => 'Asia/Yangon',
            'is_paid' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(function (array $attributes): array {
            return [
                'payment_amount' => $attributes['calculated_interest'],
                'payment_at' => $attributes['end_period_at'],
                'is_paid' => true,
            ];
        });
    }
}
