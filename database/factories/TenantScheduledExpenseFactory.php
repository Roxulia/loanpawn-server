<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantScheduledExpense;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantScheduledExpenseFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantScheduledExpense::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $start = now()->addDay()->startOfDay();

        return [
            'code' => fake()->unique()->bothify('PERFSE########'),
            'description' => 'Performance-test scheduled expense',
            'amount' => fake()->numberBetween(10_000, 500_000),
            'recurrence_type' => 'monthly',
            'start_date' => $start->toDateString(),
            'scheduled_time' => '09:00',
            'end_date' => null,
            'weekly_day' => null,
            'monthly_anchor_day' => $start->day,
            'next_due_date' => $start->toDateString(),
            'status' => 'active',
            'update_key' => 0,
        ];
    }
}
