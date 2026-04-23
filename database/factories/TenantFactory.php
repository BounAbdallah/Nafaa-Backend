<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name'         => $name,
            'slug'         => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'industry'     => fake()->randomElement(['commerce', 'agriculture', 'services', 'ict']),
            'profile_type' => fake()->randomElement(['manufacturer', 'reseller', 'wholesaler', 'service_provider']),
            'plan'         => 'demarrage',
            'is_active'    => true,
        ];
    }
}
