<?php

namespace Database\Factories;

use App\Models\Image;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProcessedImage>
 */
class ProcessedImageFactory extends Factory
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
            'corrected_path' => 'images/corrected/' . $this->faker->uuid . '.jpg',
            'detection_path' => 'images/detected/' . $this->faker->uuid . '.jpg',
            'ai_response_json' => json_encode([
                'status' => 'success',
                'message' => 'Analysis complete',
            ]),
        ];
    }
}
