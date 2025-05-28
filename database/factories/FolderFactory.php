<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Folder>
 */
class FolderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null, // Se ajustará luego para jerarquía
            'name' => $this->faker->word,
            'type' => $this->faker->randomElement(['CT', 'inversor', 'CB', 'tracker', 'string', 'modulo']),
        ];
    }
}
