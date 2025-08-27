<?php

namespace App\Console\Commands;

use App\Models\ImageBatch;
use App\Models\AnalysisBatch;
use App\Models\UnifiedBatch;
use App\Services\BatchManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MigrateLegacyBatches extends Command
{
    protected $signature = 'migrate:legacy-batches
                            {--dry-run : Simular migración sin ejecutar}
                            {--execute : Ejecutar migración real}
                            {--rollback : Revertir migración}
                            {--verify : Solo verificar integridad}
                            {--force : Forzar migración (ignorar warnings)}';

    protected $description = 'Migrar batches legacy (image_batches + analysis_batches) a sistema unificado';

    private $dryRun = false;
    private $migrationSummary = [];

    public function handle()
    {
        $this->info("🚀 MIGRACIÓN DE BATCHES LEGACY → SISTEMA UNIFICADO");
        $this->newLine();

        // ✅ Determinar modo de ejecución
        $this->dryRun = $this->option('dry-run');

        if ($this->option('rollback')) {
            return $this->handleRollback();
        }

        if ($this->option('verify')) {
            return $this->verifyIntegrity();
        }

        if (!$this->option('execute') && !$this->dryRun) {
            $this->error("❌ Especifica --dry-run o --execute");
            $this->info("💡 Usa --dry-run primero para simular la migración");
            return;
        }

        // ✅ Verificaciones pre-migración
        if (!$this->preflightChecks()) {
            return 1;
        }

        // ✅ Ejecutar migración
        $this->executeMigration();

        // ✅ Mostrar resumen
        $this->showMigrationSummary();

        return 0;
    }

    /**
     * 🔍 VERIFICACIONES PRE-MIGRACIÓN
     */
    private function preflightChecks(): bool
    {
        $this->info("🔍 Verificaciones pre-migración...");

        // ✅ Verificar servicios necesarios
        try {
            app(BatchManager::class);
            $this->line("  ✅ BatchManager disponible");
        } catch (\Throwable $e) {
            $this->error("  ❌ BatchManager no disponible: " . $e->getMessage());
            return false;
        }

        // ✅ Verificar tablas
        $requiredTables = ['image_batches', 'analysis_batches', 'unified_batches'];
        foreach ($requiredTables as $table) {
            if (!\Schema::hasTable($table)) {
                $this->error("  ❌ Tabla {$table} no existe");
                return false;
            }
        }
        $this->line("  ✅ Todas las tablas requeridas existen");

        // ✅ Contar batches legacy
        $imageBatchCount = ImageBatch::count();
        $analysisBatchCount = AnalysisBatch::count();
        $totalLegacy = $imageBatchCount + $analysisBatchCount;

        $this->table([
            'Tipo', 'Cantidad', 'Activos', 'Completados'
        ], [
            [
                'Image Batches',
                $imageBatchCount,
                ImageBatch::whereIn('status', ['processing', 'pending'])->count(),
                ImageBatch::where('status', 'completed')->count()
            ],
            [
                'Analysis Batches',
                $analysisBatchCount,
                AnalysisBatch::where('status', 'processing')->count(),
                AnalysisBatch::where('status', 'completed')->count()
            ],
            [
                'TOTAL LEGACY',
                $totalLegacy,
                '',
                ''
            ]
        ]);

        if ($totalLegacy === 0) {
            $this->warn("⚠️ No hay batches legacy para migrar");
            return false;
        }

        // ✅ Verificar conflictos en unified_batches
        $existingUnified = UnifiedBatch::count();
        if ($existingUnified > 0 && !$this->option('force')) {
            $this->warn("⚠️ Ya existen {$existingUnified} unified_batches");
            if (!$this->confirm("¿Continuar con la migración? (puede haber duplicados)")) {
                return false;
            }
        }

        // ✅ Confirmar ejecución real
        if (!$this->dryRun) {
            $this->warn("🚨 MIGRACIÓN REAL - Se modificará la base de datos");
            if (!$this->confirm("¿Estás seguro de continuar?")) {
                return false;
            }
        }

        return true;
    }

    /**
     * 🎭 EJECUTAR MIGRACIÓN
     */
    private function executeMigration(): void
    {
        $this->info($this->dryRun ? "🧪 SIMULANDO migración..." : "⚡ EJECUTANDO migración...");
        $this->newLine();

        try {
            DB::transaction(function() {
                // ✅ Migrar Image Batches
                $this->migrateImageBatches();

                // ✅ Migrar Analysis Batches
                $this->migrateAnalysisBatches();

                if ($this->dryRun) {
                    // En dry-run, hacer rollback de la transacción
                    throw new \Exception("DRY_RUN_ROLLBACK");
                }
            });

            if (!$this->dryRun) {
                $this->info("✅ Migración completada exitosamente");
            }

        } catch (\Exception $e) {
            if ($e->getMessage() === "DRY_RUN_ROLLBACK") {
                $this->info("✅ Simulación completada (sin cambios en BD)");
            } else {
                $this->error("❌ Error en migración: " . $e->getMessage());
                $this->error("🔄 Transacción revertida automáticamente");
                throw $e;
            }
        }
    }

    /**
     * 🖼️ MIGRAR IMAGE BATCHES
     */
    private function migrateImageBatches(): void
    {
        $imageBatches = ImageBatch::all();
        $migratedCount = 0;

        $this->info("🖼️ Migrando {$imageBatches->count()} Image Batches...");

        foreach ($imageBatches as $imageBatch) {
            $this->line("  Procesando ImageBatch ID: {$imageBatch->id}");

            // ✅ Mapear configuración
            $config = $this->mapImageBatchConfig($imageBatch);
            $inputData = $this->mapImageBatchInputData($imageBatch);

            // ✅ Crear UnifiedBatch equivalente
            $unifiedBatch = new UnifiedBatch([
                'project_id' => $imageBatch->project_id,
                'type' => 'image_processing',
                'status' => $this->mapStatus($imageBatch->status),
                'config' => $config,
                'input_data' => $inputData,
                'storage_path' => "projects/{$imageBatch->project_id}/processing",
                'total_items' => $imageBatch->total ?? 0,
                'processed_items' => $imageBatch->processed ?? 0,
                'failed_items' => $imageBatch->errors ?? 0,
                'active_jobs' => $this->calculateActiveJobs($imageBatch),
                'estimated_duration_seconds' => ($imageBatch->total ?? 0) * 30, // 30s por imagen
                'started_at' => $imageBatch->created_at,
                'completed_at' => $this->mapCompletedAt($imageBatch),
                'last_activity_at' => $imageBatch->updated_at,
                'created_by' => 'migration_legacy',
                'metadata' => [
                    'legacy_id' => $imageBatch->id,
                    'legacy_type' => 'image_batch',
                    'migration_date' => now()->toISOString()
                ],
                'created_at' => $imageBatch->created_at,
                'updated_at' => $imageBatch->updated_at
            ]);

            if (!$this->dryRun) {
                $unifiedBatch->save();
            }

            $migratedCount++;
            $this->line("    ✅ Migrado como UnifiedBatch ID: " . ($this->dryRun ? '[SIMULADO]' : $unifiedBatch->id));
        }

        $this->migrationSummary['image_batches'] = [
            'total' => $imageBatches->count(),
            'migrated' => $migratedCount
        ];
    }

    /**
     * 🤖 MIGRAR ANALYSIS BATCHES
     */
    private function migrateAnalysisBatches(): void
    {
        $analysisBatches = AnalysisBatch::all();
        $migratedCount = 0;

        $this->info("🤖 Migrando {$analysisBatches->count()} Analysis Batches...");

        foreach ($analysisBatches as $analysisBatch) {
            $this->line("  Procesando AnalysisBatch ID: {$analysisBatch->id}");

            // ✅ Mapear configuración
            $config = $this->mapAnalysisBatchConfig($analysisBatch);
            $inputData = $this->mapAnalysisBatchInputData($analysisBatch);

            // ✅ Crear UnifiedBatch equivalente
            $unifiedBatch = new UnifiedBatch([
                'project_id' => $analysisBatch->project_id,
                'type' => 'analysis',
                'status' => $this->mapStatus($analysisBatch->status),
                'config' => $config,
                'input_data' => $inputData,
                'storage_path' => "projects/{$analysisBatch->project_id}/analysis",
                'total_items' => $analysisBatch->total_images ?? 0,
                'processed_items' => $analysisBatch->processed_images ?? 0,
                'failed_items' => $this->calculateFailedItems($analysisBatch),
                'active_jobs' => $this->calculateAnalysisActiveJobs($analysisBatch),
                'estimated_duration_seconds' => ($analysisBatch->total_images ?? 0) * 10, // 10s por análisis
                'started_at' => $analysisBatch->created_at,
                'completed_at' => $this->mapCompletedAt($analysisBatch),
                'last_activity_at' => $analysisBatch->updated_at,
                'created_by' => 'migration_legacy',
                'metadata' => [
                    'legacy_id' => $analysisBatch->id,
                    'legacy_type' => 'analysis_batch',
                    'migration_date' => now()->toISOString()
                ],
                'created_at' => $analysisBatch->created_at,
                'updated_at' => $analysisBatch->updated_at
            ]);

            if (!$this->dryRun) {
                $unifiedBatch->save();
            }

            $migratedCount++;
            $this->line("    ✅ Migrado como UnifiedBatch ID: " . ($this->dryRun ? '[SIMULADO]' : $unifiedBatch->id));
        }

        $this->migrationSummary['analysis_batches'] = [
            'total' => $analysisBatches->count(),
            'migrated' => $migratedCount
        ];
    }

    /**
     * 🔧 MAPEO DE CONFIGURACIÓN - IMAGE BATCH
     */
    private function mapImageBatchConfig($imageBatch): array
    {
        return [
            'operation' => 'crop', // Operación por defecto
            'chunk_size' => 100,
            'legacy_type' => $imageBatch->type ?? 'processing',
            'priority' => 'normal'
        ];
    }

    /**
     * 🔧 MAPEO DE INPUT DATA - IMAGE BATCH
     */
    private function mapImageBatchInputData($imageBatch): array
    {
        return [
            'legacy_batch_id' => $imageBatch->id,
            'project_id' => $imageBatch->project_id,
            'migration_source' => 'image_batch'
        ];
    }

    /**
     * 🔧 MAPEO DE CONFIGURACIÓN - ANALYSIS BATCH
     */
    private function mapAnalysisBatchConfig($analysisBatch): array
    {
        $imageIds = [];
        if ($analysisBatch->image_ids) {
            $imageIds = is_string($analysisBatch->image_ids)
                ? json_decode($analysisBatch->image_ids, true)
                : $analysisBatch->image_ids;
        }

        return [
            'image_ids' => $imageIds ?? [],
            'chunk_size' => 25, // Chunks más pequeños para análisis
            'analysis_type' => 'ai_detection',
            'priority' => 'normal'
        ];
    }

    /**
     * 🔧 MAPEO DE INPUT DATA - ANALYSIS BATCH
     */
    private function mapAnalysisBatchInputData($analysisBatch): array
    {
        return [
            'legacy_batch_id' => $analysisBatch->id,
            'project_id' => $analysisBatch->project_id,
            'migration_source' => 'analysis_batch'
        ];
    }

    /**
     * 🔧 MAPEAR ESTADOS LEGACY → UNIFICADO
     */
    private function mapStatus(string $legacyStatus): string
    {
        return match($legacyStatus) {
            'pending' => 'pending',
            'processing' => 'processing',
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'pending'
        };
    }

    /**
     * 🔧 CALCULAR JOBS ACTIVOS
     */
    private function calculateActiveJobs($batch): int
    {
        if (in_array($batch->status, ['completed', 'failed', 'cancelled'])) {
            return 0;
        }

        $remaining = ($batch->total ?? 0) - ($batch->processed ?? 0);
        return max(0, $remaining);
    }

    private function calculateAnalysisActiveJobs($batch): int
    {
        if (in_array($batch->status, ['completed', 'failed', 'cancelled'])) {
            return 0;
        }

        $remaining = ($batch->total_images ?? 0) - ($batch->processed_images ?? 0);
        return max(0, $remaining);
    }

    /**
     * 🔧 CALCULAR ITEMS FALLIDOS
     */
    private function calculateFailedItems($analysisBatch): int
    {
        $total = $analysisBatch->total_images ?? 0;
        $processed = $analysisBatch->processed_images ?? 0;

        if ($analysisBatch->status === 'completed' && $processed < $total) {
            return $total - $processed;
        }

        return 0;
    }

    /**
     * 🔧 MAPEAR FECHA DE COMPLETADO
     */
    private function mapCompletedAt($batch): ?Carbon
    {
        if (in_array($batch->status, ['completed', 'failed', 'cancelled'])) {
            return $batch->updated_at;
        }

        return null;
    }

    /**
     * 📊 MOSTRAR RESUMEN DE MIGRACIÓN
     */
    private function showMigrationSummary(): void
    {
        $this->newLine();
        $this->info("📊 RESUMEN DE MIGRACIÓN");
        $this->line("========================");

        $totalMigrated = 0;
        foreach ($this->migrationSummary as $type => $summary) {
            $this->line("🔹 {$type}: {$summary['migrated']}/{$summary['total']} migrados");
            $totalMigrated += $summary['migrated'];
        }

        $this->newLine();
        $this->info("✅ TOTAL MIGRADO: {$totalMigrated} batches");

        if (!$this->dryRun) {
            $this->info("🎯 SIGUIENTE PASO: Actualizar rutas API para usar UnifiedBatchController");
            $this->info("💡 Verifica con: php artisan migrate:legacy-batches --verify");
        }
    }

    /**
     * 🔄 ROLLBACK DE MIGRACIÓN
     */
    private function handleRollback(): int
    {
        $this->warn("🔄 ROLLBACK - Eliminando batches migrados...");

        if (!$this->confirm("¿Eliminar todos los UnifiedBatch creados por migración?")) {
            return 0;
        }

        $migratedBatches = UnifiedBatch::where('created_by', 'migration_legacy')->get();

        $this->info("Encontrados {$migratedBatches->count()} batches migrados");

        foreach ($migratedBatches as $batch) {
            $this->line("  Eliminando UnifiedBatch ID: {$batch->id}");
            $batch->delete();
        }

        $this->info("✅ Rollback completado");
        return 0;
    }

    /**
     * ✅ VERIFICAR INTEGRIDAD POST-MIGRACIÓN
     */
    private function verifyIntegrity(): int
    {
        $this->info("🔍 VERIFICANDO integridad post-migración...");

        $imageBatchCount = ImageBatch::count();
        $analysisBatchCount = AnalysisBatch::count();
        $totalLegacy = $imageBatchCount + $analysisBatchCount;

        $migratedCount = UnifiedBatch::where('created_by', 'migration_legacy')->count();

        $this->table([
            'Tipo', 'Cantidad'
        ], [
            ['Legacy Image Batches', $imageBatchCount],
            ['Legacy Analysis Batches', $analysisBatchCount],
            ['Total Legacy', $totalLegacy],
            ['Unified Migrados', $migratedCount],
            ['Diferencia', $totalLegacy - $migratedCount]
        ]);

        if ($totalLegacy === $migratedCount) {
            $this->info("✅ INTEGRIDAD VERIFICADA - Migración completa");
            return 0;
        } else {
            $this->error("❌ PROBLEMAS DE INTEGRIDAD - Faltan " . ($totalLegacy - $migratedCount) . " batches");
            return 1;
        }
    }
}
