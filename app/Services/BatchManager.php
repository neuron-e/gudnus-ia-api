<?php

namespace App\Services;

use App\Models\UnifiedBatch;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class BatchManager
{
    /**
     * ✅ CREAR nuevo batch unificado
     */
    public function createBatch(
        int $projectId,
        string $type,
        array $config = [],
        array $inputData = [],
        string $createdBy = null
    ): UnifiedBatch {
        $project = Project::findOrFail($projectId);

        Log::info("🚀 Creando nuevo batch", [
            'project_id' => $projectId,
            'type' => $type,
            'config' => $config
        ]);

        $batch = UnifiedBatch::create([
            'project_id' => $projectId,
            'type' => $type,
            'status' => 'pending',
            'config' => $config,
            'input_data' => $inputData,
            'created_by' => $createdBy,
            'storage_path' => $this->generateStoragePath($projectId, $type),
            'last_activity_at' => now()
        ]);

        $batch->logInfo("Batch creado exitosamente");

        return $batch;
    }

    /**
     * ✅ INICIAR procesamiento del batch
     */
    public function startBatch(UnifiedBatch $batch): bool
    {
        if ($batch->status !== 'pending') {
            $batch->logError("Intento de iniciar batch en estado: {$batch->status}");
            return false;
        }

        try {
            DB::transaction(function () use ($batch) {
                $batch->update([
                    'status' => 'processing',
                    'started_at' => now(),
                    'last_activity_at' => now()
                ]);

                // Despachar el job maestro correspondiente
                $this->dispatchMasterJob($batch);
            });

            $batch->logInfo("Batch iniciado exitosamente");
            return true;

        } catch (\Throwable $e) {
            $batch->logError("Error iniciando batch: " . $e->getMessage());
            $batch->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ✅ PAUSAR batch (solo si está en processing)
     */
    public function pauseBatch(int $batchId): bool
    {
        $batch = UnifiedBatch::find($batchId);
        if (!$batch || $batch->status !== 'processing') {
            return false;
        }

        $batch->update([
            'status' => 'paused',
            'last_activity_at' => now()
        ]);

        $batch->logInfo("Batch pausado");
        return true;
    }

    /**
     * ✅ REANUDAR batch pausado
     */
    public function resumeBatch(int $batchId): bool
    {
        $batch = UnifiedBatch::find($batchId);
        if (!$batch || $batch->status !== 'paused') {
            return false;
        }

        $batch->update([
            'status' => 'processing',
            'last_activity_at' => now()
        ]);

        // Re-despachar job maestro para continuar
        $this->dispatchMasterJob($batch);

        $batch->logInfo("Batch reanudado");
        return true;
    }

    /**
     * ✅ CANCELAR batch de manera limpia
     */
    public function cancelBatch(int $batchId, string $reason = 'user_cancelled'): bool
    {
        $batch = UnifiedBatch::find($batchId);
        if (!$batch || in_array($batch->status, ['completed', 'failed', 'cancelled'])) {
            return false;
        }

        $batch->logInfo("Iniciando cancelación limpia", ['reason' => $reason]);

        try {
            DB::transaction(function () use ($batch, $reason) {
                // 1. Marcar como cancelando
                $batch->update([
                    'status' => 'cancelling',
                    'cancellation_reason' => $reason,
                    'cancellation_started_at' => now(),
                    'last_activity_at' => now()
                ]);

                // 2. Remover jobs pendientes de las colas
                $removedJobs = $this->removeJobsFromQueues($batch);
                $batch->logInfo("Jobs removidos de colas: {$removedJobs}");

                // 3. Programar finalización de cancelación después de tiempo de gracia
                \App\Jobs\CancelBatchJob::dispatch($batch->id)
                    ->delay(now()->addMinutes(5))
                    ->onQueue('maintenance');
            });

            return true;

        } catch (\Throwable $e) {
            $batch->logError("Error cancelando batch: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ ESTADO del batch con información detallada
     */
    public function getBatchStatus(int $batchId): ?array
    {
        $batch = UnifiedBatch::find($batchId);
        if (!$batch) {
            return null;
        }

        return [
            'id' => $batch->id,
            'type' => $batch->type,
            'status' => $batch->status,
            'progress' => [
                'total' => $batch->total_items,
                'processed' => $batch->processed_items,
                'failed' => $batch->failed_items,
                'skipped' => $batch->skipped_items,
                'percentage' => $batch->getProgressPercentage(),
                'active_jobs' => $batch->active_jobs
            ],
            'timing' => [
                'created_at' => $batch->created_at,
                'started_at' => $batch->started_at,
                'completed_at' => $batch->completed_at,
                'last_activity_at' => $batch->last_activity_at,
                'estimated_remaining' => $batch->getEstimatedTimeRemaining()
            ],
            'results' => [
                'generated_files' => $batch->generated_files,
                'download_url' => $batch->download_url,
                'expires_at' => $batch->expires_at
            ],
            'flags' => [
                'is_active' => $batch->isActive(),
                'is_completed' => $batch->isCompleted(),
                'is_stuck' => $batch->isStuck(),
                'has_expired' => $batch->hasExpired()
            ],
            'error_info' => [
                'last_error' => $batch->last_error,
                'error_summary' => $batch->error_summary,
                'retry_count' => $batch->retry_count
            ]
        ];
    }

    /**
     * ✅ LIMPIEZA de emergencia para proyecto
     */
    public function emergencyCleanup(int $projectId): array
    {
        Log::warning("🚨 LIMPIEZA DE EMERGENCIA proyecto {$projectId}");

        $results = [
            'cancelled_batches' => 0,
            'removed_jobs' => 0,
            'cleaned_files' => 0
        ];

        try {
            DB::transaction(function () use ($projectId, &$results) {
                // 1. Cancelar todos los batches activos
                $activeBatches = UnifiedBatch::where('project_id', $projectId)
                    ->whereIn('status', ['pending', 'processing', 'paused'])
                    ->get();

                foreach ($activeBatches as $batch) {
                    $batch->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'emergency_cleanup',
                        'completed_at' => now()
                    ]);
                    $results['cancelled_batches']++;
                }

                // 2. Remover jobs de todas las colas
                $results['removed_jobs'] = $this->removeAllProjectJobsFromQueues($projectId);

                // 3. Programar limpieza de archivos
                foreach ($activeBatches as $batch) {
                    \App\Jobs\CleanupBatchFilesJob::dispatch($batch->id)
                        ->delay(now()->addMinutes(2))
                        ->onQueue('maintenance');
                }
                $results['cleaned_files'] = $activeBatches->count();
            });

            Log::info("✅ Limpieza de emergencia completada", $results);

        } catch (\Throwable $e) {
            Log::error("❌ Error en limpieza de emergencia: " . $e->getMessage());
        }

        return $results;
    }

    /**
     * ✅ LISTAR batches de un proyecto
     * 🎯 OBTENER batches de proyecto con filtros
     */
    public function getProjectBatches(int $projectId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = UnifiedBatch::where('project_id', $projectId)
            ->orderBy('created_at', 'desc');

        // ✅ Aplicar filtros
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['only_active']) && $filters['only_active']) {
            $query->active();
        }

        $perPage = $filters['per_page'] ?? 15;
        $perPage = min(max($perPage, 1), 100); // Entre 1 y 100

        return $query->paginate($perPage);
    }

    /**
     * 🔧 FORZAR COMPLETADO de batch
     */
    public function forceCompleteBatch(int $batchId): bool
    {
        try {
            $batch = UnifiedBatch::find($batchId);

            if (!$batch) {
                Log::warning("Batch {$batchId} no encontrado para force complete");
                return false;
            }

            // ✅ Cancelar jobs activos primero
            $this->cancelActiveJobs($batch);

            // ✅ Recalcular estadísticas reales
            $realStats = $this->calculateRealBatchStats($batch);

            // ✅ Marcar como completado (con errores si hay fallas)
            $status = $realStats['failed_items'] > 0 ? 'completed_with_errors' : 'completed';

            $batch->update([
                'status' => $status,
                'processed_items' => $realStats['processed_items'],
                'failed_items' => $realStats['failed_items'],
                'active_jobs' => 0,
                'completed_at' => now(),
                'last_activity_at' => now()
            ]);

            $batch->logInfo("Batch forzado a completar como: {$status}");

            return true;

        } catch (\Throwable $e) {
            Log::error("Error forzando completado de batch {$batchId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🧹 LIMPIAR batch específico
     */
    public function cleanupBatch(int $batchId): bool
    {
        try {
            $batch = UnifiedBatch::find($batchId);

            if (!$batch) {
                return false;
            }

            // ✅ Solo limpiar batches finalizados o expirados
            if ($batch->isActive() && !$batch->isExpired()) {
                Log::warning("Batch {$batchId} está activo y no expirado, no se limpia");
                return false;
            }

            // ✅ Cancelar jobs restantes
            $this->cancelActiveJobs($batch);

            // ✅ Limpiar archivos temporales
            $this->storageManager->cleanupBatchFiles($batch->project_id, $batchId);

            // ✅ Marcar batch para limpieza (no eliminar, preservar historial)
            $batch->update([
                'status' => 'cleaned',
                'active_jobs' => 0,
                'last_activity_at' => now(),
                'metadata' => array_merge($batch->metadata ?? [], [
                    'cleaned_at' => now()->toISOString(),
                    'cleanup_reason' => 'batch_cleanup'
                ])
            ]);

            $batch->logInfo("Batch limpiado exitosamente");

            return true;

        } catch (\Throwable $e) {
            Log::error("Error limpiando batch {$batchId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🧹 LIMPIAR batches de proyecto específico
     */
    public function cleanupProjectBatches(int $projectId): int
    {
        $cleanedCount = 0;

        try {
            // ✅ Buscar batches elegibles para limpieza
            $batchesToClean = UnifiedBatch::where('project_id', $projectId)
                ->where(function($query) {
                    $query->whereIn('status', ['completed', 'completed_with_errors', 'failed', 'cancelled'])
                        ->orWhere(function($q) {
                            // Batches expirados
                            $q->whereIn('status', ['processing', 'pending'])
                                ->where('last_activity_at', '<', now()->subHours(24));
                        });
                })
                ->get();

            foreach ($batchesToClean as $batch) {
                if ($this->cleanupBatch($batch->id)) {
                    $cleanedCount++;
                }
            }

            Log::info("Limpieza de proyecto {$projectId} completada: {$cleanedCount} batches");

        } catch (\Throwable $e) {
            Log::error("Error limpiando batches de proyecto {$projectId}: " . $e->getMessage());
        }

        return $cleanedCount;
    }

    /**
     * 🔧 CALCULAR estadísticas reales del batch
     */
    private function calculateRealBatchStats(UnifiedBatch $batch): array
    {
        $stats = [
            'processed_items' => 0,
            'failed_items' => 0,
            'total_items' => $batch->total_items
        ];

        try {
            if ($batch->type === 'image_processing') {
                // ✅ Contar imágenes realmente procesadas
                $config = $batch->config ?? [];
                $imageIds = $config['image_ids'] ?? [];

                if (!empty($imageIds)) {
                    $processedCount = Image::whereIn('id', $imageIds)
                        ->where('status', 'completed')
                        ->count();

                    $failedCount = Image::whereIn('id', $imageIds)
                        ->where('status', 'error')
                        ->count();

                    $stats['processed_items'] = $processedCount;
                    $stats['failed_items'] = $failedCount;
                }

            } elseif ($batch->type === 'analysis') {
                // ✅ Contar análisis realmente completados
                $config = $batch->config ?? [];
                $imageIds = $config['image_ids'] ?? [];

                if (!empty($imageIds)) {
                    $analyzedCount = Image::whereIn('id', $imageIds)
                        ->where('is_processed', true)
                        ->count();

                    $stats['processed_items'] = $analyzedCount;
                    $stats['failed_items'] = max(0, count($imageIds) - $analyzedCount);
                }
            }

        } catch (\Throwable $e) {
            Log::error("Error calculando stats de batch {$batch->id}: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * 🛑 CANCELAR jobs activos del batch
     */
    private function cancelActiveJobs(UnifiedBatch $batch): int
    {
        try {
            // ✅ Despachar job de cancelación si hay jobs activos
            if ($batch->active_jobs > 0) {
                \App\Jobs\CancelBatchJob::dispatch($batch->id)
                    ->onQueue('batch-control');

                $batch->logInfo("Job de cancelación despachado para {$batch->active_jobs} jobs activos");
                return $batch->active_jobs;
            }

            return 0;

        } catch (\Throwable $e) {
            Log::error("Error cancelando jobs de batch {$batch->id}: " . $e->getMessage());
            return 0;
        }
    }

    // ==================== MÉTODOS PRIVADOS ====================

    /**
     * 🔧 Generar path de storage para el batch
     */
    private function generateStoragePath(int $projectId, string $type): string
    {
        $timestamp = now()->format('Y/m/d');
        return "projects/{$projectId}/{$type}/{$timestamp}";
    }

    /**
     * 🔧 Despachar job maestro según tipo de batch
     */
    private function dispatchMasterJob(UnifiedBatch $batch): void
    {
        // ✅ DESPACHAR JOB MAESTRO UNIFICADO
        \App\Jobs\ProcessBatchJob::dispatch($batch->id)
            ->onQueue('batch-control');

        $batch->logInfo("🎭 Job maestro despachado para tipo: {$batch->type}");
    }

    /**
     * 🔧 Remover jobs de colas Redis
     */
    private function removeJobsFromQueues(UnifiedBatch $batch): int
    {
        $removedCount = 0;
        $queueNames = $this->getQueuesForBatchType($batch->type);

        foreach ($queueNames as $queueName) {
            try {
                // ✅ Usar Artisan para limpiar colas específicas
                // En Fase 3 implementaremos limpieza más granular
                \Artisan::call('queue:flush', ['--queue' => $queueName]);

                $batch->logInfo("🧹 Cola limpiada: {$queueName}");
                $removedCount += 10; // Estimación

            } catch (\Throwable $e) {
                Log::error("Error limpiando cola {$queueName}: " . $e->getMessage());
            }
        }

        return $removedCount;
    }

    private function removeAllProjectJobsFromQueues(int $projectId): int
    {
        $removedCount = 0;

        // ✅ Lista de todas las colas
        $allQueues = [
            'atomic-images', 'analysis', 'downloads', 'reports',
            'zip-processing', 'batch-control', 'maintenance'
        ];

        foreach ($allQueues as $queueName) {
            try {
                // ✅ En producción, esto sería más granular
                // Por ahora, flush completo de colas relacionadas con procesamiento
                if (in_array($queueName, ['atomic-images', 'analysis', 'zip-processing'])) {
                    \Artisan::call('queue:flush', ['--queue' => $queueName]);
                    $removedCount += 20; // Estimación
                }
            } catch (\Throwable $e) {
                Log::error("Error en limpieza masiva de cola {$queueName}: " . $e->getMessage());
            }
        }

        Log::info("🧹 Limpieza masiva completada para proyecto {$projectId}: ~{$removedCount} jobs removidos");
        return $removedCount;
    }

    /**
     * 🔧 Obtener colas según tipo de batch
     */
    private function getQueuesForBatchType(string $type): array
    {
        return match($type) {
            'image_processing' => ['atomic-images'],
            'zip_processing' => ['zip-processing', 'atomic-images'],
            'analysis' => ['analysis'],
            'download_generation' => ['downloads'],
            'report_generation' => ['reports'],
            default => ['default']
        };
    }
}
