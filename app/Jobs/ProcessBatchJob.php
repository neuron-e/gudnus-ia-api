<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use App\Models\Image;
use App\Services\BatchManager;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 horas máximo
    public $tries = 2;
    public $maxExceptions = 3;

    public function __construct(public int $batchId)
    {
        $this->onQueue('batch-control');
    }

    /**
     * ✅ CLAVE ÚNICA para evitar jobs maestros duplicados
     */
    public function uniqueId(): string
    {
        return "process_batch_{$this->batchId}";
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ ProcessBatchJob: Batch {$this->batchId} no encontrado");
            return;
        }

        if (!$batch->isActive()) {
            $batch->logInfo("Job maestro ejecutado pero batch no está activo (estado: {$batch->status})");
            return;
        }

        $batch->logInfo("🎭 Job maestro iniciado para tipo: {$batch->type}");

        try {
            DB::transaction(function () use ($batch) {
                // ✅ Actualizar estado si es necesario
                if ($batch->status === 'pending') {
                    $batch->update([
                        'status' => 'processing',
                        'started_at' => now(),
                        'last_activity_at' => now()
                    ]);
                }

                // ✅ Despachar workers según el tipo de batch
                $this->dispatchWorkers($batch);
            });

        } catch (\Throwable $e) {
            $batch->logError("Error en job maestro: " . $e->getMessage());
            $this->handleBatchFailure($batch, $e);
        }
    }

    /**
     * ✅ DESPACHAR workers específicos según tipo de batch
     */
    private function dispatchWorkers(UnifiedBatch $batch): void
    {
        match($batch->type) {
            'image_processing' => $this->dispatchImageProcessingWorkers($batch),
            'zip_processing' => $this->dispatchZipProcessingWorkers($batch),
            'analysis' => $this->dispatchAnalysisWorkers($batch),
            'download_generation' => $this->dispatchDownloadWorkers($batch),
            'report_generation' => $this->dispatchReportWorkers($batch),
            default => throw new \InvalidArgumentException("Tipo de batch no soportado: {$batch->type}")
        };
    }

    /**
     * 🖼️ WORKERS para procesamiento de imágenes
     */
    private function dispatchImageProcessingWorkers(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $imageIds = $config['image_ids'] ?? [];
        $operation = $config['operation'] ?? 'crop';

        if (empty($imageIds)) {
            throw new \Exception("No se especificaron image_ids para procesamiento");
        }

        // ✅ Validar que las imágenes existen
        $validImageIds = Image::whereIn('id', $imageIds)
            ->pluck('id')
            ->toArray();

        if (empty($validImageIds)) {
            throw new \Exception("Ninguna de las imágenes especificadas existe");
        }

        // ✅ Actualizar total real
        $batch->update([
            'total_items' => count($validImageIds),
            'active_jobs' => 0 // Reset antes de despachar
        ]);

        // ✅ Despachar job por cada imagen
        foreach ($validImageIds as $imageId) {
            ProcessSingleImageJob::dispatch($imageId, $operation, $batch->id)
                ->onQueue('atomic-images');

            $batch->incrementActiveJobs();
        }

        $batch->logInfo("Despachados " . count($validImageIds) . " jobs de procesamiento de imágenes");
    }

    /**
     * 📦 WORKERS para procesamiento de ZIPs
     */
    private function dispatchZipProcessingWorkers(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $zipPath = $config['zip_path'] ?? null;
        $mapping = $config['mapping'] ?? [];

        if (!$zipPath || empty($mapping)) {
            throw new \Exception("Se requiere zip_path y mapping para procesamiento de ZIP");
        }

        // ✅ Verificar que el ZIP existe
        if (!file_exists($zipPath)) {
            throw new \Exception("Archivo ZIP no encontrado: {$zipPath}");
        }

        // ✅ Actualizar total
        $batch->update([
            'total_items' => count($mapping),
            'active_jobs' => 1 // Solo el job de extracción
        ]);

        // ✅ Despachar job de extracción y mapeo
        ProcessZipExtractionJob::dispatch($batch->id)
            ->onQueue('zip-processing');

        $batch->logInfo("Despachado job de extracción de ZIP con " . count($mapping) . " elementos");
    }

    /**
     * 🤖 WORKERS para análisis IA
     */
    private function dispatchAnalysisWorkers(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $imageIds = $config['image_ids'] ?? [];
        $chunkSize = $config['chunk_size'] ?? 50; // Chunks más pequeños para análisis

        if (empty($imageIds)) {
            throw new \Exception("No se especificaron image_ids para análisis");
        }

        // ✅ Validar imágenes procesadas (solo analizamos imágenes ya procesadas)
        $validImageIds = Image::whereIn('id', $imageIds)
            ->whereHas('processedImage')
            ->pluck('id')
            ->toArray();

        if (empty($validImageIds)) {
            throw new \Exception("No hay imágenes procesadas para analizar");
        }

        // ✅ Dividir en chunks para evitar saturar Azure API
        $chunks = array_chunk($validImageIds, $chunkSize);

        $batch->update([
            'total_items' => count($validImageIds),
            'active_jobs' => count($chunks)
        ]);

        // ✅ Despachar chunks con delay progresivo
        foreach ($chunks as $index => $chunk) {
            $delay = $index * 10; // 10 segundos entre chunks

            ProcessAnalysisChunkJob::dispatch($chunk, $batch->id, $index + 1, count($chunks))
                ->delay(now()->addSeconds($delay))
                ->onQueue('analysis');
        }

        $batch->logInfo("Despachados " . count($chunks) . " chunks de análisis para " . count($validImageIds) . " imágenes");
    }

    /**
     * 📥 WORKERS para generación de descargas
     */
    private function dispatchDownloadWorkers(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $downloadType = $config['type'] ?? 'all'; // original, processed, analyzed, all
        $projectId = $batch->project_id;

        // ✅ Obtener imágenes según tipo
        $images = $this->getImagesForDownload($projectId, $downloadType);

        if ($images->isEmpty()) {
            throw new \Exception("No hay imágenes del tipo '{$downloadType}' para descargar");
        }

        // ✅ Dividir en chunks según tamaño estimado
        $maxImagesPerZip = $config['max_images_per_zip'] ?? 500;
        $chunks = $images->chunk($maxImagesPerZip);

        $batch->update([
            'total_items' => $images->count(),
            'active_jobs' => $chunks->count()
        ]);

        // ✅ Despachar chunks de descarga
        foreach ($chunks as $index => $chunk) {
            GenerateDownloadChunkJob::dispatch(
                $chunk->pluck('id')->toArray(),
                $downloadType,
                $batch->id,
                $index + 1,
                $chunks->count()
            )->onQueue('downloads');
        }

        $batch->logInfo("Despachados " . $chunks->count() . " chunks de descarga para " . $images->count() . " imágenes");
    }

    /**
     * 📄 WORKERS para generación de reportes
     */
    private function dispatchReportWorkers(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $reportType = $config['template'] ?? 'standard';
        $includeAnalyzed = $config['include_analyzed'] ?? true;
        $projectId = $batch->project_id;

        // ✅ Obtener imágenes para el reporte
        $images = $this->getImagesForReport($projectId, $includeAnalyzed);

        if ($images->isEmpty()) {
            throw new \Exception("No hay imágenes para incluir en el reporte");
        }

        // ✅ Dividir en chunks si es un reporte muy grande
        $maxImagesPerChunk = $config['max_images_per_chunk'] ?? 200;
        $chunks = $images->chunk($maxImagesPerChunk);

        $batch->update([
            'total_items' => $images->count(),
            'active_jobs' => $chunks->count()
        ]);

        // ✅ Despachar chunks de reporte
        foreach ($chunks as $index => $chunk) {
            GenerateReportChunkJob::dispatch(
                $chunk->pluck('id')->toArray(),
                $reportType,
                $batch->id,
                $index + 1,
                $chunks->count()
            )->onQueue('reports');
        }

        $batch->logInfo("Despachados " . $chunks->count() . " chunks de reporte para " . $images->count() . " imágenes");
    }

    // ==================== MÉTODOS DE APOYO ====================

    /**
     * 🔍 Obtener imágenes para descarga según tipo
     */
    private function getImagesForDownload(int $projectId, string $type): \Illuminate\Database\Eloquent\Collection
    {
        $query = Image::with(['processedImage', 'folder'])
            ->whereHas('folder', fn($q) => $q->where('project_id', $projectId));

        return match($type) {
            'original' => $query->whereNotNull('original_path')->get(),
            'processed' => $query->whereHas('processedImage', fn($q) =>
            $q->whereNotNull('corrected_path')
            )->get(),
            'analyzed' => $query->whereHas('processedImage', fn($q) =>
            $q->whereNotNull('corrected_path')
                ->whereNotNull('ai_response_json')
            )->get(),
            'all' => $query->get(),
            default => collect()
        };
    }

    /**
     * 📋 Obtener imágenes para reporte
     */
    private function getImagesForReport(int $projectId, bool $includeAnalyzed): \Illuminate\Database\Eloquent\Collection
    {
        $query = Image::with(['processedImage', 'analysisResult', 'folder'])
            ->whereHas('folder', fn($q) => $q->where('project_id', $projectId));

        if ($includeAnalyzed) {
            $query->whereHas('processedImage', fn($q) =>
            $q->whereNotNull('ai_response_json')
            );
        }

        return $query->get();
    }

    /**
     * 💥 Manejar fallo del batch
     */
    private function handleBatchFailure(UnifiedBatch $batch, \Throwable $e): void
    {
        $batch->update([
            'status' => 'failed',
            'active_jobs' => 0,
            'completed_at' => now(),
            'last_error' => $e->getMessage(),
            'retry_count' => ($batch->retry_count ?? 0) + 1
        ]);

        // ✅ Programar limpieza automática
        CleanupBatchFilesJob::dispatch($batch->id)
            ->delay(now()->addMinutes(5))
            ->onQueue('maintenance');
    }

    /**
     * ✅ Manejo de fallos del job maestro
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessBatchJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch && $batch->isActive()) {
            $this->handleBatchFailure($batch, $exception);
        }
    }

    /**
     * ✅ Manejo de timeout
     */
    public function timeoutJob(): void
    {
        Log::error("⏰ ProcessBatchJob TIMEOUT para batch {$this->batchId}");

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("Job maestro expiró por timeout");
            $this->handleBatchFailure($batch, new \Exception("Job maestro expiró por timeout"));
        }
    }
}
