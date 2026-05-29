<?php

namespace Database\Factories;

use App\Models\CommunicationBlockType;
use App\Models\CustomerCommunicationLog;
use App\Models\CustomerCommunicationLogBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerCommunicationLogBlock>
 */
class CustomerCommunicationLogBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_communication_log_id' => CustomerCommunicationLog::factory(),
            'communication_block_type_id' => CommunicationBlockType::factory(),
            'position' => $this->faker->numberBetween(0, 5),
            'body' => $this->faker->paragraph(),
        ];
    }
}
