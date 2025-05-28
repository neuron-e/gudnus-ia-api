<?php

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ImageAnalysisResult>
 */
class ImageAnalysisResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'image_id' => Image::factory(),
            'rows' => $this->faker->numberBetween(6, 12),
            'columns' => $this->faker->numberBetween(10, 24),
            'integrity_score' => $this->faker->randomFloat(2, 0.5, 1.0),
            'luminosity_score' => $this->faker->randomFloat(2, 0.2, 0.9),
            'microcracks_count' => $this->faker->numberBetween(0, 10),
            'finger_interruptions_count' => $this->faker->numberBetween(0, 5),
            'black_edges_count' => $this->faker->numberBetween(0, 3),
            'cells_with_different_intensity' => $this->faker->numberBetween(0, 10),
        ];
    }
}
