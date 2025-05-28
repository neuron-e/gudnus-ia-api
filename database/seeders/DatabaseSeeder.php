<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        \App\Models\Project::factory(5)->create()->each(function ($project) {
            // Nivel 1 - CT
            $ct = $project->folders()->create([
                'name' => 'CT ' . $project->id,
                'type' => 'CT',
            ]);

            // Nivel 2 - Inversores
            for ($i = 1; $i <= 2; $i++) {
                $inversor = $project->folders()->create([
                    'name' => "INV $i",
                    'type' => 'inversor',
                    'parent_id' => $ct->id,
                ]);

                // Nivel 3 - CB
                $cb = $project->folders()->create([
                    'name' => "CB $i",
                    'type' => 'CB',
                    'parent_id' => $inversor->id,
                ]);

                // Nivel 4 - Tracker
                $tracker = $project->folders()->create([
                    'name' => "Tracker $i",
                    'type' => 'tracker',
                    'parent_id' => $cb->id,
                ]);

                // Nivel 5 - Strings
                for ($j = 1; $j <= 2; $j++) {
                    $string = $project->folders()->create([
                        'name' => "String $j",
                        'type' => 'string',
                        'parent_id' => $tracker->id,
                    ]);

                    // Nivel 6 - Módulos
                    for ($k = 1; $k <= 3; $k++) {
                        $modulo = $project->folders()->create([
                            'name' => "Modulo $k",
                            'type' => 'modulo',
                            'parent_id' => $string->id,
                        ]);

                        // Añadir imagen tratada y análisis
                        $image = \App\Models\Image::factory()->create(['folder_id' => $modulo->id]);
                        \App\Models\ProcessedImage::factory()->create(['image_id' => $image->id]);
                        \App\Models\ImageAnalysisResult::factory()->create(['image_id' => $image->id]);
                    }
                }
            }
        });
    }
}
