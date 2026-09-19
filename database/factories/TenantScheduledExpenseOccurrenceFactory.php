<?php

namespace Database\Factories;

use App\Models\CoreModule\TenantScheduledExpenseOccurrence;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantScheduledExpenseOccurrenceFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = TenantScheduledExpenseOccurrence::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();
        $dueDate = now()->subDay();

        return [
            'description' => 'Performance-test scheduled expense occurrence',
            'amount' => fake()->numberBetween(10_000, 500_000),
            'due_date' => $dueDate->toDateString(),
            'due_time' => '09:00',
            'status' => 'pending',
            'attempt_count' => 0,
            'last_error' => null,
            'executed_at' => null,
            'cancelled_at' => null,
        ];
    }
}
