<?php

namespace Database\Factories;

use App\Models\CommunicationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CommunicationType>
 */
class CommunicationTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($name),
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
            'is_system' => false,
        ];
    }
}
