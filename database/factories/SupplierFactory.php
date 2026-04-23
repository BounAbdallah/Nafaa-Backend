<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name'         => $this->faker->company(),
            'phone'        => $this->faker->phoneNumber(),
            'email'        => $this->faker->companyEmail(),
            'contact_name' => $this->faker->name(),
            'address'      => $this->faker->address(),
            'city'         => $this->faker->city(),
            'country'      => 'SN',
            'notes'        => $this->faker->sentence(),
            'is_active'    => true,
        ];
    }
}
