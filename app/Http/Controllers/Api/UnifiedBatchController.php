<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Project;
use App\Models\UnifiedBatch;
use App\Services\BatchManager;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UnifiedBatchController extends Controller
{
    public function __construct(
        private BatchManager $batchManager,
        private StorageManager $storageManager
    ) {}

    /**
     * ✅ CREAR nuevo batch unificado
     */
    public function store(Request $request, Project $project)
    {
        $validated = $request->validate([
            'type' => 'required|in:image_processing,zip_processing,analysis,download_generation,report_generation',
            'config' => 'array',
            'input_data' => 'array',
            'auto_start' => 'boolean'
        ]);

        try {
            $batch = $this->batchManager->createBatch(
                projectId: $project->id,
                type: $validated['type'],
                config: $validated['config'] ?? [],
                inputData: $validated['input_data'] ?? [],
                createdBy: auth()->user()?->email ?? 'system'
            );

            // ✅ Auto-iniciar si se especifica
            if ($validated['auto_start'] ?? false) {
                $started = $this->batchManager->startBatch($batch);
                if (!$started) {
                    return response()->json([
                        'error' => 'Batch creado pero no se pudo iniciar',
                        'batch' => $batch
                    ], 422);
                }
            }

            return response()->json([
                'message' => 'Batch creado exitosamente',
                'batch' => $batch->fresh(),
                'status' => $this->batchManager->getBatchStatus($batch->id)
            ], 201);

        } catch (\Throwable $e) {
            Log::error("Error creando batch: " . $e->getMessage());

            return response()->json([
                'error' => 'Error creando batch: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ OBTENER estado detallado del batch
     */
    public function show(Project $project, int $batchId)
    {
        $batch = UnifiedBatch::where('project_id', $project->id)
            ->where('id', $batchId)
            ->firstOrFail();

        $status = $this->batchManager->getBatchStatus($batchId);

        return response()->json([
            'batch' => $batch,
            'status' => $status,
            'storage_info' => [
                'base_path' => $this->storageManager->getProjectBasePath($project->id),
                'batch_path' => $this->storageManager->getBatchPath($project->id, $batchId)
            ]
        ]);
    }

    /**
     * ✅ LISTAR batches del proyecto con filtros
     */
    public function index(Request $request, Project $project)
    {
        $validated = $request->validate([
            'type' => 'nullable|in:image_processing,zip_processing,analysis,download_generation,report_generation',
            'status' => 'nullable|in:pending,processing,paused,completed,completed_with_errors,failed,cancelled,cancelling',
            'only_active' => 'boolean',
            'per_page' => 'integer|min:1|max:100'
        ]);

        $batches = $this->batchManager->getProjectBatches($project->id, $validated);

        return response()->json([
            'batches' => $batches,
            'summary' => [
                'total' => UnifiedBatch::where('project_id', $project->id)->count(),
                'active' => UnifiedBatch::where('project_id', $project->id)->active()->count(),
                'stuck' => UnifiedBatch::where('project_id', $project->id)->stuck()->count()
            ]
        ]);
    }

    /**
     * ✅ INICIAR batch pendiente
     */
    public function start(Project $project, int $batchId)
    {
        $batch = UnifiedBatch::where('project_id', $project->id)
            ->where('id', $batchId)
            ->firstOrFail();

        if ($batch->status !== 'pending') {
            return response()->json([
                'error' => "No se puede iniciar batch en estado: {$batch->status}"
            ], 422);
        }

        $started = $this->batchManager->startBatch($batch);

        if (!$started) {
            return response()->json([
                'error' => 'No se pudo iniciar el batch'
            ], 500);
        }

        return response()->json([
            'message' => 'Batch iniciado exitosamente',
            'batch' => $batch->fresh(),
            'status' => $this->batchManager->getBatchStatus($batchId)
        ]);
    }

    /**
     * ✅ PAUSAR batch en procesamiento
     */
    public function pause(Project $project, int $batchId)
    {
        $batch = UnifiedBatch::where('project_id', $project->id)
            ->where('id', $batchId)
            ->firstOrFail();

        $paused = $this->batchManager->pauseBatch($batchId);

        if (!$paused) {
            return response()->json([
                'error' => "No se puede pausar batch en estado: {$batch->status}"
            ], 422);
        }

        return response()->json([
            'message' => 'Batch pausado exitosamente',
            'batch' => $batch->fresh()
        ]);
    }

    /**
     * ✅ REANUDAR batch pausado
     */
    public function resume(Project $project, int $batchId)
    {
        $batch = UnifiedBatch::where('project_id', $project->id)
            ->where('id', $batchId)
            ->firstOrFail();

        $resumed = $this->batchManager->resumeBatch($batchId);

        if (!$resumed) {
            return response()->json([
                'error' => "No se puede reanudar batch en estado: {$batch->status}"
            ], 422);
        }

        return response()->json([
            'message' => 'Batch reanudado exitosamente',
            'batch' => $batch->fresh()
        ]);
    }

    /**
     * ✅ CANCELAR batch (limpieza completa)
     */
    public function cancel(Request $request, Project $project, int $batchId)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:255'
        ]);

        $batch = UnifiedBatch::where('project_id', $project->id)
            ->where('id', $batchId)
            ->firstOrFail();

        if (in_array($batch->status, ['completed', 'failed', 'cancelled'])) {
            return response()->json([
                'error' => "No se puede cancelar batch en estado: {$batch->status}"
            ], 422);
        }

        $cancelled = $this->batchManager->cancelBatch(
            $batchId,
            $validated['reason'] ?? 'user_cancelled'
        );

        if (!$cancelled) {
            return response()->json([
                'error' => 'No se pudo cancelar el batch'
            ], 500);
        }

        return response()->json([
            'message' => 'Batch cancelado exitosamente (limpieza en progreso)',
            'batch' => $batch->fresh()
        ]);
    }

    /**
     * ✅ LIMPIEZA DE EMERGENCIA del proyecto completo
     */
    public function emergencyCleanup(Project $project)
    {
        Log::warning("🚨 LIMPIEZA DE EMERGENCIA solicitada para proyecto {$project->id}");

        $results = $this->batchManager->emergencyCleanup($project->id);

        return response()->json([
            'message' => 'Limpieza de emergencia completada',
            'results' => $results,
            'warning' => 'Todos los procesamientos activos han sido cancelados'
        ]);
    }

    /**
     * ✅ CREAR batch de prueba para testing
     */
    public function createTestBatch(Request $request, Project $project)
    {
        $validated = $request->validate([
            'type' => 'required|in:image_processing,zip_processing,analysis,download_generation,report_generation',
            'simulate_items' => 'integer|min:1|max:100'
        ]);

        // Crear configuración de prueba
        $testConfig = [
            'test_mode' => true,
            'simulated_items' => $validated['simulate_items'] ?? 10,
            'created_by' => 'test_api'
        ];

        $testInputData = [
            'test_data' => "Batch de prueba creado en " . now()->toISOString(),
            'simulate_processing' => true
        ];

        try {
            $batch = $this->batchManager->createBatch(
                projectId: $project->id,
                type: $validated['type'],
                config: $testConfig,
                inputData: $testInputData,
                createdBy: 'test_api'
            );

            // Configurar datos simulados
            $batch->update([
                'total_items' => $validated['simulate_items'] ?? 10,
                'estimated_duration_seconds' => ($validated['simulate_items'] ?? 10) * 5 // 5 segundos por item
            ]);

            return response()->json([
                'message' => 'Batch de prueba creado exitosamente',
                'batch' => $batch,
                'test_info' => [
                    'type' => $validated['type'],
                    'simulated_items' => $validated['simulate_items'] ?? 10,
                    'note' => 'Este es un batch de prueba para validar la nueva arquitectura'
                ]
            ], 201);

        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error creando batch de prueba: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ DIAGNÓSTICO del sistema de batches
     */
    public function systemDiagnostic()
    {
        $diagnostic = [
            'timestamp' => now()->toISOString(),
            'storage_health' => $this->storageManager->checkStorageHealth(),
            'batch_statistics' => [
                'total_batches' => UnifiedBatch::count(),
                'active_batches' => UnifiedBatch::active()->count(),
                'stuck_batches' => UnifiedBatch::stuck()->count(),
                'expired_batches' => UnifiedBatch::expired()->count()
            ],
            'batch_types' => UnifiedBatch::selectRaw('type, status, COUNT(*) as count')
                ->groupBy('type', 'status')
                ->get()
                ->groupBy('type'),
            'recent_activity' => UnifiedBatch::where('last_activity_at', '>=', now()->subHour())
                ->orderBy('last_activity_at', 'desc')
                ->limit(10)
                ->get(['id', 'type', 'status', 'last_activity_at'])
        ];

        return response()->json($diagnostic);
    }

    // ================= MÉTODOS LEGACY ADAPTERS =================
    // 🔄 Compatibilidad temporal con el sistema legacy

    /**
     * 🔄 LEGACY: Obtener batches del proyecto (formato legacy)
     */
    public function legacyGetProjectBatches(Project $project)
    {
        try {
            // ✅ Obtener unified batches del proyecto
            $unifiedBatches = UnifiedBatch::where('project_id', $project->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // ✅ Convertir a formato legacy esperado por frontend
            $legacyFormat = [
                'image_batches' => [],
                'analysis_batches' => []
            ];

            foreach ($unifiedBatches as $batch) {
                $legacyBatch = $this->convertToLegacyFormat($batch);

                if ($batch->type === 'image_processing') {
                    $legacyFormat['image_batches'][] = $legacyBatch;
                } elseif ($batch->type === 'analysis') {
                    $legacyFormat['analysis_batches'][] = $legacyBatch;
                }
            }

            return response()->json($legacyFormat);

        } catch (\Throwable $e) {
            Log::error("Error en legacyGetProjectBatches: " . $e->getMessage());
            return response()->json(['error' => 'Error obteniendo batches'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Reintentar imágenes pendientes
     */
    public function legacyRetryPendingImages(Project $project)
    {
        try {
            // ✅ Buscar imágenes pendientes del proyecto
            $pendingImages = Image::whereHas('folder', function($q) use ($project) {
                $q->where('project_id', $project->id);
            })
                ->where('status', 'pending')
                ->orWhere('is_processed', false)
                ->get();

            if ($pendingImages->isEmpty()) {
                return response()->json([
                    'message' => 'No hay imágenes pendientes de procesamiento',
                    'retried' => 0
                ]);
            }

            // ✅ Crear nuevo batch unificado para reintento
            $batch = $this->batchManager->createBatch(
                projectId: $project->id,
                type: 'image_processing',
                config: [
                    'operation' => 'crop',
                    'image_ids' => $pendingImages->pluck('id')->toArray(),
                    'retry' => true
                ],
                inputData: [
                    'legacy_retry' => true,
                    'original_source' => 'retry_pending_images'
                ],
                createdBy: 'legacy_retry_system'
            );

            // ✅ Iniciar batch automáticamente
            $this->batchManager->startBatch($batch);

            return response()->json([
                'message' => "Reiniciando procesamiento de {$pendingImages->count()} imágenes",
                'retried' => $pendingImages->count(),
                'batch_id' => $batch->id
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en legacyRetryPendingImages: " . $e->getMessage());
            return response()->json(['error' => 'Error reiniciando imágenes'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Reintentar análisis pendientes
     */
    public function legacyRetryPendingAnalysis(Project $project)
    {
        try {
            // ✅ Buscar imágenes que necesitan análisis
            $pendingImages = Image::whereHas('folder', function($q) use ($project) {
                $q->where('project_id', $project->id);
            })
                ->whereHas('processedImage', function($q) {
                    $q->whereNotNull('corrected_path');
                })
                ->where('is_processed', false)
                ->get();

            if ($pendingImages->isEmpty()) {
                return response()->json([
                    'message' => 'No hay imágenes pendientes de análisis IA',
                    'retried' => 0
                ]);
            }

            // ✅ Crear nuevo batch unificado para análisis
            $batch = $this->batchManager->createBatch(
                projectId: $project->id,
                type: 'analysis',
                config: [
                    'image_ids' => $pendingImages->pluck('id')->toArray(),
                    'chunk_size' => 25,
                    'retry' => true
                ],
                inputData: [
                    'legacy_retry' => true,
                    'original_source' => 'retry_pending_analysis'
                ],
                createdBy: 'legacy_retry_system'
            );

            // ✅ Iniciar batch automáticamente
            $this->batchManager->startBatch($batch);

            return response()->json([
                'message' => "Reiniciando análisis de {$pendingImages->count()} imágenes",
                'retried' => $pendingImages->count(),
                'batch_id' => $batch->id
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en legacyRetryPendingAnalysis: " . $e->getMessage());
            return response()->json(['error' => 'Error reiniciando análisis'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Limpiar proyecto por fuerza
     */
    public function legacyForceCleanProject(Project $project)
    {
        try {
            // ✅ Cancelar todos los batches activos del proyecto
            $activeBatches = UnifiedBatch::where('project_id', $project->id)
                ->active()
                ->get();

            $cancelledCount = 0;
            foreach ($activeBatches as $batch) {
                if ($this->batchManager->cancelBatch($batch->id)) {
                    $cancelledCount++;
                }
            }

            // ✅ Limpiar batches obsoletos
            $cleanedCount = $this->batchManager->cleanupProjectBatches($project->id);

            return response()->json([
                'message' => 'Proyecto limpiado exitosamente',
                'cancelled_batches' => $cancelledCount,
                'cleaned_batches' => $cleanedCount,
                'total_affected' => $cancelledCount + $cleanedCount
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en legacyForceCleanProject: " . $e->getMessage());
            return response()->json(['error' => 'Error limpiando proyecto'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Obtener detalles de batch por tipo
     */
    public function legacyGetBatchDetails(int $batchId, string $type = 'image')
    {
        try {
            $batch = UnifiedBatch::findOrFail($batchId);
            $legacyBatch = $this->convertToLegacyFormat($batch);

            return response()->json([
                'batch' => $legacyBatch,
                'type' => $type,
                'status' => $this->batchManager->getBatchStatus($batchId)
            ]);

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Batch no encontrado'], 404);
        }
    }

    /**
     * 🔄 LEGACY: Forzar completar batch por tipo
     */
    public function legacyForceCompleteBatch(int $batchId, string $type = 'image')
    {
        try {
            $batch = UnifiedBatch::findOrFail($batchId);

            // ✅ Forzar completado usando BatchManager
            $success = $this->batchManager->forceCompleteBatch($batchId);

            if ($success) {
                return response()->json([
                    'message' => 'Batch marcado como completado',
                    'type' => $type
                ]);
            } else {
                return response()->json(['error' => 'No se pudo completar batch'], 500);
            }

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error completando batch'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Cancelar batch por tipo
     */
    public function legacyCancelBatch(int $batchId, string $type = 'image')
    {
        try {
            $success = $this->batchManager->cancelBatch($batchId);

            if ($success) {
                return response()->json([
                    'message' => 'Batch cancelado correctamente',
                    'type' => $type
                ]);
            } else {
                return response()->json(['error' => 'No se pudo cancelar batch'], 500);
            }

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Error cancelando batch'], 500);
        }
    }

    /**
     * 🔄 LEGACY: Limpiar batches antiguos
     */
    public function legacyCleanupOldBatches()
    {
        try {
            // ✅ Usar BatchManager para limpieza
            $cleanedCount = 0;

            // Limpiar batches expirados
            $expiredBatches = UnifiedBatch::expired()->get();
            foreach ($expiredBatches as $batch) {
                if ($this->batchManager->cleanupBatch($batch->id)) {
                    $cleanedCount++;
                }
            }

            return response()->json([
                'message' => 'Limpieza completada',
                'cleaned_batches' => $cleanedCount
            ]);

        } catch (\Throwable $e) {
            Log::error("Error en legacyCleanupOldBatches: " . $e->getMessage());
            return response()->json(['error' => 'Error en limpieza'], 500);
        }
    }

    // ================= MÉTODOS AUXILIARES =================

    /**
     * 🔧 Convertir UnifiedBatch a formato legacy
     */
    private function convertToLegacyFormat(UnifiedBatch $batch): array
    {
        // ✅ Mapear campos unificados a formato legacy esperado
        $legacyFormat = [
            'id' => $batch->id,
            'project_id' => $batch->project_id,
            'status' => $this->mapStatusToLegacy($batch->status),
            'created_at' => $batch->created_at,
            'updated_at' => $batch->updated_at,
            'metadata' => $batch->metadata
        ];

        // ✅ Campos específicos según tipo
        if ($batch->type === 'image_processing') {
            $legacyFormat = array_merge($legacyFormat, [
                'type' => 'processing',
                'total' => $batch->total_items,
                'processed' => $batch->processed_items,
                'errors' => $batch->failed_items
            ]);
        } elseif ($batch->type === 'analysis') {
            $legacyFormat = array_merge($legacyFormat, [
                'total_images' => $batch->total_items,
                'processed_images' => $batch->processed_items,
                'image_ids' => $batch->config['image_ids'] ?? []
            ]);
        }

        return $legacyFormat;
    }

    /**
     * 🔧 Mapear estados unificados a legacy
     */
    private function mapStatusToLegacy(string $unifiedStatus): string
    {
        return match($unifiedStatus) {
            'pending' => 'pending',
            'processing' => 'processing',
            'completed' => 'completed',
            'completed_with_errors' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'failed', // Legacy no tenía 'cancelled'
            default => 'pending'
        };
    }
}
