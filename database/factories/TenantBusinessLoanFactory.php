<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantBusinessLoan;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantBusinessLoanFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantBusinessLoan::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $amount = fake()->numberBetween(100_000, 10_000_000);

        return [
            'code' => fake()->unique()->bothify('PERFBL########'),
            'update_key' => 0,
            'is_deleted' => false,
            'amount' => $amount,
            'principal_balance' => $amount,
            'apply_interest' => false,
            'interest_rate' => null,
            'interest_type_id' => null,
            'compound_schedule_enabled' => false,
            'description' => 'Performance-test business loan',
            'tag' => 'performance',
            'is_paid' => false,
        ];
    }

    public function accruing(int $interestTypeId, float $interestRate = 5): static
    {
        return $this->state(fn (): array => [
            'apply_interest' => true,
            'interest_rate' => $interestRate,
            'interest_type_id' => $interestTypeId,
            'interest_anchor_at' => now()->subMonth(),
        ]);
    }
}
