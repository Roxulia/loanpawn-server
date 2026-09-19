<?php

namespace Database\Factories;

use App\Models\PawnModule\PawnLoanContractSlip;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PawnLoanContractSlipFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = PawnLoanContractSlip::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'slip_no' => fake()->unique()->bothify('PERF-LS-########'),
            'loan_amount' => fake()->numberBetween(50_000, 5_000_000),
            'interest_rate' => fake()->randomElement([1, 2, 3, 5, 8, 10]),
            'expire_at' => now()->addMonths(3)->startOfDay(),
            'status' => 'active',
            'expiry_quota' => 3,
            'expiry_quota_type' => 'Month',
            'compound_schedule_enabled' => false,
            'is_deleted' => false,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'expire_at' => now()->subMonth()->startOfDay(),
        ]);
    }

    public function redeemed(): static
    {
        return $this->state(fn (): array => ['status' => 'redeemed']);
    }
}
