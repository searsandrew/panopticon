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
        $name = $this->faker->name();

        return [
            'netsuite_customer_id' => $this->faker->numberBetween(1000, 9999),
            'customer_account_number' => $this->faker->bothify('?-####'),
            'name' => $name,
            'normalized_name' => CustomerContact::normalizeName($name),
            'last_used_at' => now(),
        ];
    }
}
