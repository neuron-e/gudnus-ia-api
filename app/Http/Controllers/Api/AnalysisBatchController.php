<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalysisBatch;
use App\Models\ImageBatch;
use App\Models\Project;
use App\Models\Image;
use App\Jobs\ProcessImageImmediatelyJob;
use App\Jobs\ProcessBulkImagesJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalysisBatchController extends Controller
{
    public function processingStatus(Project $project)
    {
        $batch = AnalysisBatch::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        if (!$batch) {
            return response()->json([
                'processing' => false,
                'progress' => 100,
                'batch_id' => null
            ]);
        }

        // ✅ Verificar si el batch está colgado (más de 30 minutos sin actualizar)
        $isStuck = $batch->updated_at < Carbon::now()->subMinutes(30);

        if ($isStuck) {
            Log::warning("AnalysisBatch {$batch->id} parece estar colgado. Última actualización: {$batch->updated_at}");
        }

        $progress = $batch->total_images > 0
            ? round(($batch->processed_images / $batch->total_images) * 100)
            : 0;

        // ✅ Marcar como completado si ha terminado
        if ($progress >= 100) {
            $batch->update(['status' => 'completed']);
        }

        return response()->json([
            'processing' => $progress < 100,
            'progress' => $progress,
            'processed' => $batch->processed_images,
            'total' => $batch->total_images,
            'batch_id' => $batch->id,
            'is_stuck' => $isStuck,
            'last_update' => $batch->updated_at->diffForHumans()
        ]);
    }

    public function processingStatusImage(Project $project)
    {
        // ✅ Auto-limpiar batches muy antiguos (ajustado para lotes grandes)
        $veryOldBatches = ImageBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->where('updated_at', '<', Carbon::now()->subHours(8))  // ✅ 8 horas para lotes muy grandes
            ->get();

        foreach ($veryOldBatches as $oldBatch) {
            $oldBatch->update(['status' => 'failed']);
            Log::warning("Auto-marcando batch {$oldBatch->id} como fallido por ser muy antiguo (8+ horas)");
        }

        $batch = ImageBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        if (!$batch) {
            return response()->json([
                'processing' => false,
                'progress' => 100,
                'batch_id' => null
            ]);
        }

        // ✅ Calcular tiempo límite basado en el tamaño del batch
        $expectedMinutes = $this->calculateExpectedProcessingTime($batch);
        $isStuck = $batch->updated_at < Carbon::now()->subMinutes($expectedMinutes);

        if ($isStuck) {
            Log::warning("ImageBatch {$batch->id} parece estar colgado. Tamaño: {$batch->total}, Tiempo esperado: {$expectedMinutes}min, Última actualización: {$batch->updated_at}");
        }

        $totalDone = $batch->processed + ($batch->errors ?? 0);
        $progress = $batch->total > 0
            ? round(($totalDone / $batch->total) * 100)
            : 0;

        // ✅ Marcar como completado si ha terminado
        if ($progress >= 100) {
            $finalStatus = ($batch->errors ?? 0) > 0 ? 'completed_with_errors' : 'completed';
            $batch->update(['status' => $finalStatus]);

            return response()->json([
                'processing' => false,
                'progress' => 100,
                'batch_id' => null,
                'completed_batch_id' => $batch->id
            ]);
        }

        return response()->json([
            'processing' => true,
            'progress' => $progress,
            'processed' => $batch->processed,
            'total' => $batch->total,
            'errors' => $batch->errors ?? 0,
            'batch_id' => $batch->id,
            'is_stuck' => $isStuck,
            'last_update' => $batch->updated_at->diffForHumans(),
            'expected_completion' => $this->estimateCompletionTime($batch),
            'debug_info' => [
                'batch_size' => $batch->total,
                'expected_minutes' => $expectedMinutes,
                'minutes_since_update' => $batch->updated_at->diffInMinutes(now())
            ]
        ]);
    }

    /**
     * ✅ Calcular tiempo esperado de procesamiento basado en el tamaño del batch
     */
    private function calculateExpectedProcessingTime($batch)
    {
        if (!$batch->total) return 30; // Default 30 min para batches pequeños

        // Tiempo base por imagen (estimación conservadora)
        $minutesPerImage = 1.5; // 90 segundos por imagen

        // Factor de concurrencia (estimamos 5-10 jobs simultáneos)
        $concurrencyFactor = 0.2; // 20% del tiempo secuencial

        $expectedMinutes = ($batch->total * $minutesPerImage * $concurrencyFactor);

        // Límites mínimos y máximos
        return max(60, min($expectedMinutes, 360)); // Entre 1 hora y 6 horas máximo
    }

    /**
     * ✅ Estimar tiempo de finalización
     */
    private function estimateCompletionTime($batch)
    {
        if ($batch->processed <= 0) return 'Calculando...';

        $processingRate = $batch->processed / max(1, $batch->updated_at->diffInMinutes($batch->created_at));
        $remaining = $batch->total - $batch->processed;
        $estimatedMinutes = $remaining / max(0.1, $processingRate);

        if ($estimatedMinutes < 60) {
            return round($estimatedMinutes) . ' minutos';
        } else {
            return round($estimatedMinutes / 60, 1) . ' horas';
        }
    }

    /**
     * Obtener detalles de un batch específico
     */
    public function getBatchDetails($batchId, $type = 'image')
    {
        if ($type === 'analysis') {
            $batch = AnalysisBatch::findOrFail($batchId);

            // Para analysis batch, verificar imágenes procesadas vs no procesadas
            $imageIds = is_string($batch->image_ids) ? json_decode($batch->image_ids, true) : $batch->image_ids;
            $processedCount = Image::whereIn('id', $imageIds)->where('is_processed', true)->count();
            $pendingCount = count($imageIds) - $processedCount;

            return response()->json([
                'batch' => $batch,
                'pending_count' => $pendingCount,
                'processed_count' => $processedCount,
                'is_stuck' => $batch->updated_at < Carbon::now()->subMinutes(30)
            ]);
        } else {
            $batch = ImageBatch::findOrFail($batchId);

            // ✅ Obtener imágenes del proyecto a través de la relación folder
            $projectImages = Image::whereHas('folder', function($q) use ($batch) {
                $q->where('project_id', $batch->project_id);
            })
                ->with(['processedImage', 'folder'])
                ->get();

            $pendingImages = $projectImages->filter(function($image) {
                return !$image->processedImage ||
                    !$image->processedImage->corrected_path ||
                    ($image->processedImage->status ?? 'pending') === 'pending';
            });

            $failedImages = $projectImages->filter(function($image) {
                return $image->processedImage &&
                    ($image->processedImage->status ?? '') === 'error';
            });

            return response()->json([
                'batch' => $batch,
                'pending_count' => $pendingImages->count(),
                'failed_count' => $failedImages->count(),
                'pending_images' => $pendingImages->values(),
                'failed_images' => $failedImages->values(),
                'is_stuck' => $batch->updated_at < Carbon::now()->subMinutes(30)
            ]);
        }
    }

    /**
     * Reintentar procesamiento de imágenes pendientes (recorte)
     */
    public function retryPendingImages(Project $project)
    {
        // ✅ Buscar imágenes sin procesar o con errores a través de la relación folder
        $pendingImages = Image::whereHas('folder', function($q) use ($project) {
            $q->where('project_id', $project->id);
        })
            ->where(function($query) {
                $query->whereDoesntHave('processedImage')
                    ->orWhereHas('processedImage', function($q) {
                        $q->where(function($subQ) {
                            $subQ->whereNull('corrected_path')
                                ->orWhere('status', 'error')
                                ->orWhere('status', 'pending');
                        });
                    });
            })
            ->with(['folder'])
            ->get();

        if ($pendingImages->isEmpty()) {
            return response()->json([
                'message' => 'No hay imágenes pendientes de procesar',
                'retried' => 0
            ]);
        }

        // Crear un nuevo batch para el reintento
        $retryBatch = ImageBatch::create([
            'project_id' => $project->id,
            'type' => 'retry-processing',
            'total' => $pendingImages->count(),
            'status' => 'processing',
            'processed' => 0,
            'errors' => 0
        ]);

        // Despachar jobs para cada imagen pendiente
        foreach ($pendingImages as $image) {
            dispatch(new ProcessImageImmediatelyJob($image->id, $retryBatch->id));
        }

        Log::info("Reiniciando procesamiento de {$pendingImages->count()} imágenes en batch {$retryBatch->id}");

        return response()->json([
            'message' => "Reiniciando procesamiento de {$pendingImages->count()} imágenes",
            'retried' => $pendingImages->count(),
            'batch_id' => $retryBatch->id
        ]);
    }

    /**
     * Reintentar análisis de imágenes pendientes
     */
    public function retryPendingAnalysis(Project $project)
    {
        // ✅ Buscar imágenes procesadas pero sin análisis a través de la relación folder
        $pendingImages = Image::whereHas('folder', function($q) use ($project) {
            $q->where('project_id', $project->id);
        })
            ->whereHas('processedImage', function($q) {
                $q->whereNotNull('corrected_path');
            })
            ->where(function($query) {
                $query->where('is_processed', false)
                    ->orWhereDoesntHave('analysisResult');
            })
            ->with(['folder', 'processedImage'])
            ->get();

        if ($pendingImages->isEmpty()) {
            return response()->json([
                'message' => 'No hay imágenes pendientes de análisis',
                'retried' => 0
            ]);
        }

        $imageIds = $pendingImages->pluck('id')->toArray();

        // Crear un nuevo batch para el reintento
        $retryBatch = AnalysisBatch::create([
            'project_id' => $project->id,
            'image_ids' => json_encode($imageIds),
            'total_images' => count($imageIds),
            'processed_images' => 0,
            'status' => 'processing'
        ]);

        // Despachar job de análisis masivo
        dispatch(new ProcessBulkImagesJob($imageIds, null, $retryBatch->id));

        Log::info("Reiniciando análisis de {$pendingImages->count()} imágenes en batch {$retryBatch->id}");

        return response()->json([
            'message' => "Reiniciando análisis de {$pendingImages->count()} imágenes",
            'retried' => $pendingImages->count(),
            'batch_id' => $retryBatch->id
        ]);
    }

    /**
     * Forzar completar un batch
     */
    public function forceCompleteBatch($batchId, $type = 'image')
    {
        if ($type === 'analysis') {
            $batch = AnalysisBatch::findOrFail($batchId);

            // Verificar que realmente esté colgado
            if ($batch->updated_at > Carbon::now()->subMinutes(10)) {
                return response()->json([
                    'error' => 'El batch se actualizó recientemente. No parece estar colgado.'
                ], 400);
            }

            // Contar imágenes realmente analizadas
            $imageIds = is_string($batch->image_ids) ? json_decode($batch->image_ids, true) : $batch->image_ids;
            $actualProcessed = Image::whereIn('id', $imageIds)->where('is_processed', true)->count();

            $batch->update([
                'processed_images' => $actualProcessed,
                'status' => 'completed'
            ]);

        } else {
            $batch = ImageBatch::findOrFail($batchId);

            // Verificar que realmente esté colgado
            if ($batch->updated_at > Carbon::now()->subMinutes(10)) {
                return response()->json([
                    'error' => 'El batch se actualizó recientemente. No parece estar colgado.'
                ], 400);
            }

            // ✅ Contar imágenes realmente procesadas a través de la relación folder
            $actualProcessed = Image::whereHas('folder', function($q) use ($batch) {
                $q->where('project_id', $batch->project_id);
            })
                ->whereHas('processedImage', function($q) {
                    $q->whereNotNull('corrected_path');
                })
                ->count();

            $batch->update([
                'processed' => $actualProcessed,
                'status' => $actualProcessed >= $batch->total ? 'completed' : 'completed_with_errors'
            ]);
        }

        Log::info("Batch {$batchId} ({$type}) forzado a completar");

        return response()->json([
            'message' => 'Batch marcado como completado',
            'type' => $type
        ]);
    }

    /**
     * Cancelar un batch
     */
    public function cancelBatch($batchId, $type = 'image')
    {
        if ($type === 'analysis') {
            $batch = AnalysisBatch::findOrFail($batchId);
        } else {
            $batch = ImageBatch::findOrFail($batchId);
        }

        // ✅ Cambiar 'cancelled' por 'failed' que es un valor válido
        $batch->update(['status' => 'failed']);

        return response()->json([
            'message' => 'Batch cancelado correctamente',
            'type' => $type
        ]);
    }

    /**
     * Limpiar batches antiguos (con timeouts ajustados para lotes grandes)
     */
    public function cleanupOldBatches()
    {
        // ✅ Timeouts más realistas para lotes grandes
        $stuckImageBatches = ImageBatch::whereIn('status', ['processing', 'pending'])
            ->where('updated_at', '<', Carbon::now()->subHours(8))  // 8 horas para procesamiento
            ->get();

        $stuckAnalysisBatches = AnalysisBatch::where('status', 'processing')
            ->where('updated_at', '<', Carbon::now()->subHours(6))  // 6 horas para análisis
            ->get();

        foreach ($stuckImageBatches as $batch) {
            $batch->update(['status' => 'failed']);
            Log::warning("ImageBatch {$batch->id} (tamaño: {$batch->total}) marcado como fallido por timeout de 8+ horas");
        }

        foreach ($stuckAnalysisBatches as $batch) {
            $batch->update(['status' => 'failed']);
            Log::warning("AnalysisBatch {$batch->id} (tamaño: {$batch->total_images}) marcado como fallido por timeout de 6+ horas");
        }

        $totalCleaned = $stuckImageBatches->count() + $stuckAnalysisBatches->count();

        return response()->json([
            'cleaned' => $totalCleaned,
            'image_batches' => $stuckImageBatches->count(),
            'analysis_batches' => $stuckAnalysisBatches->count(),
            'message' => "Se limpiaron {$totalCleaned} batches colgados (timeouts: 8h procesamiento, 6h análisis)",
            'details' => [
                'image_batches_cleaned' => $stuckImageBatches->map(fn($b) => ['id' => $b->id, 'size' => $b->total, 'age_hours' => $b->updated_at->diffInHours(now())]),
                'analysis_batches_cleaned' => $stuckAnalysisBatches->map(fn($b) => ['id' => $b->id, 'size' => $b->total_images, 'age_hours' => $b->updated_at->diffInHours(now())])
            ]
        ]);
    }

    /**
     * ✅ Limpiar TODOS los batches activos de un proyecto específico (emergencia)
     */
    public function forceCleanProject(Project $project)
    {
        $imageBatches = ImageBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->get();

        $analysisBatches = AnalysisBatch::where('project_id', $project->id)
            ->where('status', 'processing')
            ->get();

        foreach ($imageBatches as $batch) {
            $batch->update(['status' => 'failed']);
        }

        foreach ($analysisBatches as $batch) {
            $batch->update(['status' => 'failed']);
        }

        $totalCleaned = $imageBatches->count() + $analysisBatches->count();

        Log::info("Limpieza forzada del proyecto {$project->id}: {$totalCleaned} batches marcados como fallidos");

        return response()->json([
            'cleaned' => $totalCleaned,
            'image_batches' => $imageBatches->count(),
            'analysis_batches' => $analysisBatches->count(),
            'message' => "Se detuvieron todos los procesamientos del proyecto ({$totalCleaned} batches)"
        ]);
    }

    /**
     * 🔍 DEBUG: Ver todos los batches de un proyecto para diagnosticar problemas
     */
    public function debugProjectBatches(Project $project)
    {
        $imageBatches = ImageBatch::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($batch) {
                return [
                    'id' => $batch->id,
                    'type' => 'ImageBatch',
                    'status' => $batch->status,
                    'total' => $batch->total,
                    'processed' => $batch->processed,
                    'errors' => $batch->errors,
                    'created_at' => $batch->created_at,
                    'updated_at' => $batch->updated_at,
                    'minutes_since_update' => $batch->updated_at->diffInMinutes(now()),
                    'batch_type' => $batch->type ?? 'unknown'
                ];
            });

        $analysisBatches = AnalysisBatch::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($batch) {
                return [
                    'id' => $batch->id,
                    'type' => 'AnalysisBatch',
                    'status' => $batch->status,
                    'total_images' => $batch->total_images,
                    'processed_images' => $batch->processed_images,
                    'created_at' => $batch->created_at,
                    'updated_at' => $batch->updated_at,
                    'minutes_since_update' => $batch->updated_at->diffInMinutes(now()),
                ];
            });

        // Verificar qué está devolviendo la consulta actual
        $currentImageBatch = ImageBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        $currentAnalysisBatch = AnalysisBatch::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        return response()->json([
            'project_id' => $project->id,
            'image_batches' => $imageBatches,
            'analysis_batches' => $analysisBatches,
            'current_image_batch' => $currentImageBatch ? [
                'id' => $currentImageBatch->id,
                'status' => $currentImageBatch->status,
                'updated_at' => $currentImageBatch->updated_at,
                'minutes_old' => $currentImageBatch->updated_at->diffInMinutes(now())
            ] : null,
            'current_analysis_batch' => $currentAnalysisBatch ? [
                'id' => $currentAnalysisBatch->id,
                'status' => $currentAnalysisBatch->status,
                'updated_at' => $currentAnalysisBatch->updated_at,
                'minutes_old' => $currentAnalysisBatch->updated_at->diffInMinutes(now())
            ] : null,
            'total_processing_image' => ImageBatch::where('project_id', $project->id)->where('status', 'processing')->count(),
            'total_pending_image' => ImageBatch::where('project_id', $project->id)->where('status', 'pending')->count(),
            'total_processing_analysis' => AnalysisBatch::where('project_id', $project->id)->where('status', 'processing')->count(),
        ]);
    }

    /**
     * Obtener resumen de todos los batches de un proyecto
     */
    public function getProjectBatches(Project $project)
    {
        $imageBatches = ImageBatch::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($batch) {
                $batch->batch_type = 'image';
                return $batch;
            });

        $analysisBatches = AnalysisBatch::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($batch) {
                $batch->batch_type = 'analysis';
                return $batch;
            });

        $allBatches = $imageBatches->concat($analysisBatches)
            ->sortByDesc('created_at')
            ->values();

        $currentImageBatch = ImageBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        $currentAnalysisBatch = AnalysisBatch::where('project_id', $project->id)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        return response()->json([
            'all_batches' => $allBatches,
            'image_batches' => $imageBatches,
            'analysis_batches' => $analysisBatches,
            'current_image_batch' => $currentImageBatch ? [
                'id' => $currentImageBatch->id,
                'status' => $currentImageBatch->status,
                'updated_at' => $currentImageBatch->updated_at,
                'minutes_old' => $currentImageBatch->updated_at->diffInMinutes(now())
            ] : null,
            'current_analysis_batch' => $currentAnalysisBatch ? [
                'id' => $currentAnalysisBatch->id,
                'status' => $currentAnalysisBatch->status,
                'updated_at' => $currentAnalysisBatch->updated_at,
                'minutes_old' => $currentAnalysisBatch->updated_at->diffInMinutes(now())
            ] : null,
            'total_processing_image' => ImageBatch::where('project_id', $project->id)->where('status', 'processing')->count(),
            'total_pending_image' => ImageBatch::where('project_id', $project->id)->where('status', 'pending')->count(),
            'total_processing_analysis' => AnalysisBatch::where('project_id', $project->id)->where('status', 'processing')->count(),
        ]);
    }
}
