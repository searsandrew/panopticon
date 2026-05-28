<?php

namespace Database\Factories;

use App\Models\CustomerContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerContact>
 */
class CustomerContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'netsuite_customer_id' => fake()->numberBetween(1000, 9999),
            'customer_account_number' => fake()->bothify('?-####'),
            'name' => $name,
            'normalized_name' => CustomerContact::normalizeName($name),
            'last_used_at' => now(),
        ];
    }
}
