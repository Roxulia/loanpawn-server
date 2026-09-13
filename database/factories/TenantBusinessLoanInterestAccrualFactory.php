<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantBusinessLoanInterestAccrual;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantBusinessLoanInterestAccrualFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantBusinessLoanInterestAccrual::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $start = now()->subMonth()->startOfDay();

        return [
            'principal_amount' => fake()->numberBetween(100_000, 10_000_000),
            'calculated_interest' => fake()->numberBetween(5_000, 500_000),
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
