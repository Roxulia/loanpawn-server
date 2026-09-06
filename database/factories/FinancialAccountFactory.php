<?php

namespace Database\Factories;

use App\Models\FinancialAccount;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinancialAccountFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = FinancialAccount::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        return [
            'account_number' => fake()->unique()->numerify('PERF########'),
            'account_name' => fake()->randomElement(['Main Cash', 'Counter Cash', 'Bank']),
            'account_code' => fake()->unique()->bothify('PA######'),
            'balance' => fake()->numberBetween(50_000_000, 500_000_000),
            'is_active' => true,
            'is_default' => false,
            'is_deleted' => false,
            'allow_negative_balance' => false,
        ];
    }
}
