<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph,
            'panel_brand' => $this->faker->randomElement(['Jinko', 'Trina', 'Canadian Solar']),
            'panel_model' => strtoupper($this->faker->bothify('TS-###X')),
            'installation_name' => $this->faker->company,
            'inspector_name' => $this->faker->name,
            'cell_count' => $this->faker->numberBetween(60, 144),
            'column_count' => $this->faker->numberBetween(6, 12),
            'user_id' => \App\Models\User::factory(),
        ];
    }
}
