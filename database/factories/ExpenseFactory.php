<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $categories = array_keys(Expense::categories());
        $methods    = array_keys(Expense::paymentMethods());

        return [
            'category'       => $this->faker->randomElement($categories),
            'description'    => $this->faker->sentence(),
            'amount'         => $this->faker->numberBetween(5000, 500000),
            'payment_method' => $this->faker->randomElement($methods),
            'expense_date'   => $this->faker->dateTimeBetween('-3 months', 'now'),
            'notes'          => $this->faker->optional()->sentence(),
        ];
    }
}
