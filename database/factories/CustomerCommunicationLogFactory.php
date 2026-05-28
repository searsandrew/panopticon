<?php

namespace Database\Factories;

use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCommunicationLog>
 */
class CustomerCommunicationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'netsuite_customer_id' => fake()->numberBetween(1000, 9999),
            'customer_account_number' => fake()->bothify('?-####'),
            'customer_name' => fake()->company(),
            'netsuite_sales_rep_id' => 2214,
            'communication_type_id' => CommunicationType::factory(),
            'contact_person_name' => fake()->name(),
            'contact_at' => now(),
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
            'last_autosaved_at' => now(),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CustomerCommunicationLog::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }
}
