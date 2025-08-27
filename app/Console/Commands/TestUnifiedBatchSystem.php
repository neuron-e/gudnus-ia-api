<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\UnifiedBatch;
use App\Models\Image;
use App\Models\Folder;
use App\Services\BatchManager;
use App\Services\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestUnifiedBatchSystem extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'test:unified-batch
                            {action : test, create, status, cleanup}
                            {--project= : ID del proyecto}
                            {--type= : Tipo de batch}
                            {--count=5 : Número de elementos para testing}';

    /**
     * The console command description.
     */
    protected $description = 'Probar el sistema unificado de batches';

    public function handle()
    {
        $action = $this->argument('action');

        match($action) {
            'test' => $this->runFullTest(),
            'create' => $this->createTestBatch(),
            'status' => $this->showBatchStatus(),
            'cleanup' => $this->cleanupSystem(),
            default => $this->error("Acción no válida: {$action}")
        };
    }

    /**
     * 🧪 PRUEBA COMPLETA del sistema
     */
    private function runFullTest(): void
    {
        $this->info("🚀 Iniciando prueba completa del sistema unificado de batches");
        $this->newLine();

        try {
            // ✅ 1. Verificar servicios básicos
            $this->testBasicServices();

            // ✅ 2. Crear proyecto de prueba
            $project = $this->createTestProject();

            // ✅ 3. Crear datos de prueba
            $this->createTestData($project);

            // ✅ 4. Probar diferentes tipos de batch
            $this->testBatchTypes($project);

            // ✅ 5. Verificar resultados
            $this->verifyResults($project);

            $this->info("✅ Prueba completa finalizada exitosamente");

        } catch (\Throwable $e) {
            $this->error("❌ Error en prueba: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }

    /**
     * 🔧 Verificar servicios básicos
     */
    private function testBasicServices(): void
    {
        $this->info("🔧 Verificando servicios básicos...");

        // BatchManager
        $batchManager = app(BatchManager::class);
        $this->line("  ✅ BatchManager disponible");

        // StorageManager
        $storageManager = app(StorageManager::class);
        $health = $storageManager->checkStorageHealth();

        if ($health['wasabi_connection']) {
            $this->line("  ✅ Conexión Wasabi OK");
        } else {
            $this->warn("  ⚠️ Problema con Wasabi: " . implode(', ', $health['errors']));
        }

        $this->line("  ✅ Servicios básicos verificados");
    }

    /**
     * 🏗️ Crear proyecto de prueba
     */
    private function createTestProject(): Project
    {
        $this->info("🏗️ Creando proyecto de prueba...");

        $project = Project::create([
            'name' => 'Test Unified Batch ' . now()->format('Y-m-d H:i:s'),
            'user_id' => 1, // Asumiendo que hay un usuario con ID 1
            'description' => 'Proyecto para probar el sistema unificado de batches',
            'panel_brand' => 'Test Solar',
            'panel_model' => 'TST-300W',
            'cell_count' => 72,
            'column_count' => 6
        ]);

        $this->line("  ✅ Proyecto creado: {$project->name} (ID: {$project->id})");
        return $project;
    }

    /**
     * 📋 Crear datos de prueba
     */
    private function createTestData(Project $project): void
    {
        $this->info("📋 Creando datos de prueba...");

        $count = (int) $this->option('count');

        // ✅ Crear carpetas
        $folders = [];
        for ($i = 1; $i <= min($count, 3); $i++) {
            $folder = Folder::create([
                'project_id' => $project->id,
                'name' => "Modulo_Test_{$i}",
                'type' => 'modulo',
                'parent_id' => null
            ]);
            $folders[] = $folder;
            $this->line("  📁 Carpeta creada: {$folder->name}");
        }

        // ✅ Crear imágenes simuladas (adaptado a estructura real)
        foreach ($folders as $folder) {
            for ($j = 1; $j <= $count; $j++) {
                $filename = "test_image_{$folder->id}_{$j}.jpg";
                $originalPath = "projects/{$project->id}/original/{$filename}";

                $image = Image::create([
                    'folder_id' => $folder->id,
                    'project_id' => $project->id, // ✅ Se asigna automáticamente por boot()
                    'original_path' => $originalPath,
                    'status' => 'pending',
                    'is_processed' => false,
                    'is_counted' => false
                ]);

                // ✅ Simular algunas imágenes como "procesadas"
                if ($j <= $count / 2) {
                    $image->update([
                        'is_processed' => true,
                        'processed_at' => now(),
                        'status' => 'completed'
                    ]);

                    // ✅ Crear ProcessedImage si existe la relación
                    if (method_exists($image, 'processedImage')) {
                        $image->processedImage()->create([
                            'corrected_path' => "projects/{$project->id}/processed/{$filename}",
                            'status' => 'completed'
                        ]);
                    }
                }

                $this->line("  🖼️ Imagen creada: {$image->filename}");
            }
        }

        $totalImages = $project->images()->count();
        $this->line("  ✅ {$totalImages} imágenes de prueba creadas");
    }

    /**
     * 🎭 Probar diferentes tipos de batch
     */
    private function testBatchTypes(Project $project): void
    {
        $this->info("🎭 Probando diferentes tipos de batch...");

        $batchManager = app(BatchManager::class);
        $imageIds = $project->images()->pluck('id')->toArray();

        // ✅ Test 1: Image Processing
        $this->line("  🖼️ Creando batch de procesamiento de imágenes...");
        $imageBatch = $batchManager->createBatch(
            projectId: $project->id,
            type: 'image_processing',
            config: [
                'operation' => 'crop',
                'image_ids' => array_slice($imageIds, 0, 3)
            ],
            createdBy: 'test_command'
        );

        $batchManager->startBatch($imageBatch);
        $this->line("    ✅ Batch imagen iniciado: {$imageBatch->id}");

        // ✅ Test 2: Analysis (adaptado a estructura real)
        $processedImageIds = $project->images()
            ->processed() // ✅ Usa scope actualizado
            ->pluck('id')
            ->toArray();

        if (!empty($processedImageIds)) {
            $this->line("  🤖 Creando batch de análisis IA...");
            $analysisBatch = $batchManager->createBatch(
                projectId: $project->id,
                type: 'analysis',
                config: [
                    'image_ids' => array_slice($processedImageIds, 0, 2),
                    'chunk_size' => 2
                ],
                createdBy: 'test_command'
            );

            $batchManager->startBatch($analysisBatch);
            $this->line("    ✅ Batch análisis iniciado: {$analysisBatch->id}");
        }

        // ✅ Test 3: Download Generation
        $this->line("  📥 Creando batch de descarga...");
        $downloadBatch = $batchManager->createBatch(
            projectId: $project->id,
            type: 'download_generation',
            config: [
                'type' => 'all',
                'include_metadata' => true
            ],
            createdBy: 'test_command'
        );

        $batchManager->startBatch($downloadBatch);
        $this->line("    ✅ Batch descarga iniciado: {$downloadBatch->id}");

        // ✅ Test 4: Report Generation
        $this->line("  📄 Creando batch de reporte...");
        $reportBatch = $batchManager->createBatch(
            projectId: $project->id,
            type: 'report_generation',
            config: [
                'template' => 'standard',
                'include_analyzed' => true
            ],
            createdBy: 'test_command'
        );

        $batchManager->startBatch($reportBatch);
        $this->line("    ✅ Batch reporte iniciado: {$reportBatch->id}");

        $this->line("  ✅ Todos los tipos de batch iniciados");
    }

    /**
     * 📊 Verificar resultados
     */
    private function verifyResults(Project $project): void
    {
        $this->info("📊 Verificando resultados...");

        // Esperar un momento para que los jobs se procesen
        $this->line("  ⏳ Esperando procesamiento inicial...");
        sleep(5);

        $batches = UnifiedBatch::where('project_id', $project->id)->get();

        $this->table(['ID', 'Tipo', 'Estado', 'Total', 'Procesados', 'Fallidos', 'Jobs Activos'],
            $batches->map(function($batch) {
                return [
                    $batch->id,
                    $batch->type,
                    $batch->status,
                    $batch->total_items,
                    $batch->processed_items,
                    $batch->failed_items,
                    $batch->active_jobs
                ];
            })->toArray()
        );

        $activeCount = $batches->where('status', 'processing')->count();
        $completedCount = $batches->whereIn('status', ['completed', 'completed_with_errors'])->count();

        $this->line("  📈 Resumen:");
        $this->line("    - Batches activos: {$activeCount}");
        $this->line("    - Batches completados: {$completedCount}");
        $this->line("    - Total batches: {$batches->count()}");
    }

    /**
     * 🏗️ Crear batch específico
     */
    private function createTestBatch(): void
    {
        $projectId = $this->option('project');
        $type = $this->option('type') ?? 'image_processing';
        $count = (int) $this->option('count');

        if (!$projectId) {
            $this->error("Se requiere --project=ID");
            return;
        }

        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Proyecto {$projectId} no encontrado");
            return;
        }

        $this->info("🏗️ Creando batch de prueba tipo: {$type}");

        $batchManager = app(BatchManager::class);

        $config = match($type) {
            'image_processing' => [
                'operation' => 'crop',
                'image_ids' => $project->images()->limit($count)->pluck('id')->toArray()
            ],
            'analysis' => [
                'image_ids' => $project->images()->processed()->limit($count)->pluck('id')->toArray(),
                'chunk_size' => min($count, 10)
            ],
            'download_generation' => [
                'type' => 'all',
                'include_metadata' => true
            ],
            'report_generation' => [
                'template' => 'standard',
                'include_analyzed' => true
            ],
            default => []
        };

        try {
            $batch = $batchManager->createBatch(
                projectId: $projectId,
                type: $type,
                config: $config,
                createdBy: 'test_command'
            );

            $this->info("✅ Batch creado: {$batch->id}");

            if ($this->confirm("¿Iniciar el batch ahora?", true)) {
                $batchManager->startBatch($batch);
                $this->info("🚀 Batch iniciado");
            }

        } catch (\Throwable $e) {
            $this->error("❌ Error creando batch: " . $e->getMessage());
        }
    }

    /**
     * 📊 Mostrar estado de batches
     */
    private function showBatchStatus(): void
    {
        $projectId = $this->option('project');

        $query = UnifiedBatch::query();

        if ($projectId) {
            $query->where('project_id', $projectId);
            $this->info("📊 Estado de batches para proyecto {$projectId}:");
        } else {
            $this->info("📊 Estado de todos los batches:");
        }

        $batches = $query->orderBy('created_at', 'desc')->limit(10)->get();

        if ($batches->isEmpty()) {
            $this->warn("No hay batches encontrados");
            return;
        }

        $this->table(['ID', 'Proyecto', 'Tipo', 'Estado', 'Progreso', 'Creado', 'Última Actividad'],
            $batches->map(function($batch) {
                $progress = $batch->total_items > 0
                    ? round(($batch->processed_items + $batch->failed_items) / $batch->total_items * 100, 1) . '%'
                    : '0%';

                return [
                    $batch->id,
                    $batch->project_id,
                    $batch->type,
                    $batch->status,
                    "{$progress} ({$batch->processed_items}/{$batch->total_items})",
                    $batch->created_at->format('H:i:s'),
                    $batch->last_activity_at?->format('H:i:s') ?? 'N/A'
                ];
            })->toArray()
        );
    }

    /**
     * 🧹 Limpiar sistema de prueba
     */
    private function cleanupSystem(): void
    {
        $this->warn("🧹 Limpiando sistema de prueba...");

        if (!$this->confirm("¿Estás seguro? Esto eliminará TODOS los batches y proyectos de prueba", false)) {
            $this->info("Operación cancelada");
            return;
        }

        try {
            DB::transaction(function() {
                // Eliminar batches de prueba
                $testBatches = UnifiedBatch::where('created_by', 'test_command')->get();
                foreach ($testBatches as $batch) {
                    $batch->delete();
                }
                $this->line("  ✅ {$testBatches->count()} batches de prueba eliminados");

                // Eliminar proyectos de prueba
                $testProjects = Project::where('name', 'like', 'Test Unified Batch%')->get();
                foreach ($testProjects as $project) {
                    // Eliminar imágenes relacionadas (cascada automática)
                    $project->images()->delete();
                    // Eliminar carpetas
                    $project->folders()->delete();
                    // Eliminar proyecto
                    $project->delete();
                }
                $this->line("  ✅ {$testProjects->count()} proyectos de prueba eliminados");
            });

            $this->info("✅ Limpieza completada");

        } catch (\Throwable $e) {
            $this->error("❌ Error en limpieza: " . $e->getMessage());
        }
    }
}
