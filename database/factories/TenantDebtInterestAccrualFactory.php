<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantDebtInterestAccrual;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantDebtInterestAccrualFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantDebtInterestAccrual::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $start = now()->subMonth()->startOfDay();

        return [
            'principal_amount' => fake()->numberBetween(1_000, 500_000),
            'calculated_interest' => fake()->numberBetween(500, 50_000),
            'paid_amount' => 0,
            'compounded_amount' => 0,
            'compounded_at' => null,
            'start_period_at' => $start,
            'end_period_at' => $start->copy()->addMonth()->subSecond(),
            'period_timezone' => 'Asia/Yangon',
            'is_paid' => false,
        ];
    }
}
