<?php

namespace Database\Factories;

use App\Models\CommunicationBlockType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommunicationBlockType>
 */
class CommunicationBlockTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($name),
            'sort_order' => $this->faker->numberBetween(1, 100),
            'is_active' => true,
            'is_system' => false,
        ];
    }
}
