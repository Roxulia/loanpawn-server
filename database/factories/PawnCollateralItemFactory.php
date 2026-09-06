<?php

namespace Database\Factories;

use App\Models\PawnModule\PawnCollateralItem;
use Database\Factories\Concerns\RequiresTestingEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PawnCollateralItemFactory extends Factory
{
    use RequiresTestingEnvironment;

    protected $model = PawnCollateralItem::class;

    public function definition(): array
    {
        $this->ensureTestingEnvironment();

        $type = fake()->randomElement(['Normal', 'Jewellery']);

        return [
            'code' => fake()->unique()->bothify('PERFI########'),
            'type' => $type,
            'name' => $type === 'Jewellery' ? fake()->randomElement(['Gold Ring', 'Gold Chain', 'Bracelet']) : fake()->randomElement(['Phone', 'Laptop', 'Watch', 'Camera']),
            'estimated_value' => fake()->numberBetween(100_000, 8_000_000),
            'item_status' => 'pawned',
            'contains_gemstones' => false,
            'quantity' => fake()->numberBetween(1, 3),
            'minimum_retail_price' => fake()->numberBetween(100_000, 8_000_000),
            'is_deleted' => false,
        ];
    }
}
