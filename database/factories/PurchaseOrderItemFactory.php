<?php

namespace Database\Factories;

use App\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        return [
            'description'       => $this->faker->sentence(3),
            'unit'              => $this->faker->randomElement(['pièce', 'kg', 'boîte']),
            'quantity'          => $this->faker->numberBetween(1, 100),
            'unit_price'        => $this->faker->numberBetween(1000, 50000),
            'received_quantity' => 0,
        ];
    }
}
