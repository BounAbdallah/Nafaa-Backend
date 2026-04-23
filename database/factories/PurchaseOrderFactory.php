<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            'reference'    => 'CMD-' . $this->faker->unique()->numberBetween(1000, 9999),
            'status'       => $this->faker->randomElement(array_keys(PurchaseOrder::$statuses)),
            'order_date'   => $this->faker->dateTimeBetween('-1 month', 'now'),
            'total_amount' => 0,
            'notes'        => $this->faker->optional()->sentence(),
        ];
    }
}
