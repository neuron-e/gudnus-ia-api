<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\UnifiedBatch;
use App\Models\Image;
use App\Models\Folder;
use App\Services\BatchManager;
use Illuminate\Console\Command;

class QuickTest extends Command
{
    protected $signature = 'test:quick';
    protected $description = 'Verificación rápida del sistema unificado';

    public function handle()
    {
        $this->info("🚀 Verificación rápida del sistema unificado...");
        $this->newLine();

        try {
            // ✅ 1. Verificar estructura de images
            $this->info("📊 1. Verificando estructura de tabla images...");
            $this->verifyImagesStructure();

            // ✅ 2. Verificar servicios
            $this->info("🔧 2. Verificando servicios...");
            $this->verifyServices();

            // ✅ 3. Crear proyecto simple
            $this->info("🏗️ 3. Creando proyecto de prueba...");
            $project = $this->createSimpleProject();

            // ✅ 4. Crear imagen de prueba
            $this->info("🖼️ 4. Creando imagen de prueba...");
            $image = $this->createSimpleImage($project);

            // ✅ 5. Crear batch simple
            $this->info("🎭 5. Creando batch simple...");
            $batch = $this->createSimpleBatch($project, $image);

            // ✅ 6. Verificar resultado
            $this->info("✅ 6. Verificando resultado...");
            $this->verifyResult($batch);

            $this->newLine();
            $this->info("🎉 VERIFICACIÓN COMPLETADA EXITOSAMENTE");

        } catch (\Throwable $e) {
            $this->error("❌ Error: " . $e->getMessage());
            $this->error("Ubicación: " . $e->getFile() . ":" . $e->getLine());
        }
    }

    private function verifyImagesStructure(): void
    {
        $columns = \Schema::getColumnListing('images');

        $expected = ['id', 'project_id', 'folder_id', 'original_path', 'status', 'is_processed'];
        $missing = [];

        foreach ($expected as $column) {
            if (!in_array($column, $columns)) {
                $missing[] = $column;
            }
        }

        if (!empty($missing)) {
            throw new \Exception("Faltan columnas en images: " . implode(', ', $missing));
        }

        $this->line("  ✅ Estructura de images OK");
    }

    private function verifyServices(): void
    {
        $batchManager = app(BatchManager::class);
        $this->line("  ✅ BatchManager OK");

        $storageManager = app(\App\Services\StorageManager::class);
        $this->line("  ✅ StorageManager OK");
    }

    private function createSimpleProject(): Project
    {
        $project = Project::create([
            'name' => 'Test Quick ' . now()->format('H:i:s'),
            'user_id' => 1,
            'description' => 'Proyecto para verificación rápida'
        ]);

        // Crear carpeta
        $folder = Folder::create([
            'project_id' => $project->id,
            'name' => 'Test_Folder',
            'type' => 'modulo'
        ]);

        $this->line("  ✅ Proyecto {$project->id} creado");
        return $project;
    }

    private function createSimpleImage(Project $project): Image
    {
        $folder = $project->folders()->first();

        $image = Image::create([
            'folder_id' => $folder->id,
            'project_id' => $project->id,
            'original_path' => "projects/{$project->id}/test_image.jpg",
            'status' => 'pending',
            'is_processed' => false
        ]);

        $this->line("  ✅ Imagen {$image->id} creada (filename: {$image->filename})");
        return $image;
    }

    private function createSimpleBatch(Project $project, Image $image): UnifiedBatch
    {
        $batchManager = app(BatchManager::class);

        $batch = $batchManager->createBatch(
            projectId: $project->id,
            type: 'image_processing',
            config: [
                'operation' => 'crop',
                'image_ids' => [$image->id]
            ],
            createdBy: 'quick_test'
        );

        $this->line("  ✅ Batch {$batch->id} creado");
        return $batch;
    }

    private function verifyResult(UnifiedBatch $batch): void
    {
        $status = app(BatchManager::class)->getBatchStatus($batch->id);

        $this->line("  📊 Estado del batch:");
        $this->line("    - ID: {$batch->id}");
        $this->line("    - Tipo: {$batch->type}");
        $this->line("    - Estado: {$batch->status}");
        $this->line("    - Total items: {$batch->total_items}");

        if ($batch->status === 'pending') {
            $this->line("  ✅ Batch creado correctamente en estado pending");
        } else {
            $this->warn("  ⚠️ Batch en estado inesperado: {$batch->status}");
        }
    }
}
