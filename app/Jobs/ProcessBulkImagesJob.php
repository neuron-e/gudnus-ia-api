<?php

namespace App\Jobs;

use App\Mail\ImagesProcessedMail;
use App\Models\AnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessBulkImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA SERVIDOR POTENTE
    public $timeout = 600;      // ✅ 10 minutos por chunk (era 5)
    public $tries = 3;          // ✅ Más reintentos
    public $maxExceptions = 3;

    // ✅ Backoff más inteligente
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    public function __construct(
        public array $imageIds,
        public ?string $notifyEmail = null,
        public ?int $batchId = null,
        public int $chunkIndex = 0,
        public int $totalChunks = 1
    ) {}

    public function handle()
    {
        $startTime = microtime(true);
        $chunkSize = count($this->imageIds);

        Log::info("🤖 [CHUNK {$this->chunkIndex}/{$this->totalChunks}] Iniciando análisis IA masivo", [
            'chunk_size' => $chunkSize,
            'batch_id' => $this->batchId,
            'attempt' => $this->attempts(),
            'total_chunks' => $this->totalChunks
        ]);

        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        if ($batch) {
            $batch->touch(); // Actualizar timestamp
        }

        try {
            // ✅ VALIDAR CHUNK ANTES DE PROCESAR
            $validImageIds = $this->validateChunk($this->imageIds);

            if (empty($validImageIds)) {
                Log::warning("⚠️ Chunk {$this->chunkIndex} sin imágenes válidas");
                return;
            }

            Log::info("✅ Chunk {$this->chunkIndex}: {$chunkSize} imágenes solicitadas, " . count($validImageIds) . " válidas");

            // ✅ DESPACHAR JOBS INDIVIDUALES CON DELAYS OPTIMIZADOS
            $this->dispatchIndividualJobs($validImageIds, $batch);

            // ✅ PROGRAMAR VERIFICACIÓN DE COMPLETADO SOLO EN ÚLTIMO CHUNK
            if ($this->isLastChunk() && $this->notifyEmail && $batch) {
                $this->scheduleCompletionCheck($batch, count($validImageIds));
            }

            $processingTime = round(microtime(true) - $startTime, 2);
            Log::info("✅ Chunk {$this->chunkIndex} despachado en {$processingTime}s");

        } catch (\Throwable $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ Error en chunk {$this->chunkIndex}: " . $e->getMessage(), [
                'processing_time' => $processingTime,
                'batch_id' => $this->batchId,
                'chunk_size' => $chunkSize
            ]);

            throw $e;
        }
    }

    /**
     * ✅ VALIDACIÓN DE CHUNK OPTIMIZADA
     */
    private function validateChunk(array $imageIds): array
    {
        // ✅ Verificar que las imágenes existen y están listas para análisis
        $validImages = \App\Models\Image::whereIn('id', $imageIds)
            ->whereHas('processedImage', function($q) {
                $q->whereNotNull('corrected_path');
            })
            ->where('is_processed', false)
            ->pluck('id')
            ->toArray();

        $invalidCount = count($imageIds) - count($validImages);
        if ($invalidCount > 0) {
            Log::info("ℹ️ Chunk {$this->chunkIndex}: {$invalidCount} imágenes omitidas (ya procesadas o sin recortar)");
        }

        return $validImages;
    }

    /**
     * ✅ DESPACHO OPTIMIZADO DE JOBS INDIVIDUALES
     */
    private function dispatchIndividualJobs(array $imageIds, ?AnalysisBatch $batch): void
    {
        // ✅ CONFIGURACIÓN DINÁMICA DE DELAYS SEGÚN CARGA
        $baseDelay = $this->calculateBaseDelay();
        $maxConcurrent = $this->getMaxConcurrentJobs();

        Log::info("📋 Despachando {count($imageIds)} jobs individuales", [
            'chunk_index' => $this->chunkIndex,
            'base_delay' => $baseDelay,
            'max_concurrent_estimate' => $maxConcurrent
        ]);

        foreach ($imageIds as $index => $imageId) {
            // ✅ DELAY PROGRESIVO PARA DISTRIBUIR CARGA
            $delay = $this->calculateJobDelay($index, $baseDelay, count($imageIds));

            // ✅ USAR COLA DE ALTA PRIORIDAD PARA CHUNKS PEQUEÑOS
            $queue = count($imageIds) <= 10 ? 'high-priority' : 'analysis';

            ProcessImageImmediatelyJob::dispatch($imageId, $this->batchId)
                ->delay(now()->addSeconds($delay))
                ->onQueue($queue);
        }

        Log::info("✅ Jobs despachados para chunk {$this->chunkIndex}");
    }

    /**
     * ✅ CÁLCULO DINÁMICO DE DELAY BASE
     */
    private function calculateBaseDelay(): int
    {
        // ✅ Delay base más agresivo aprovechando el servidor potente
        return match(true) {
            $this->totalChunks <= 2 => 1,   // Proyectos pequeños: muy rápido
            $this->totalChunks <= 5 => 2,   // Proyectos medianos: rápido
            $this->totalChunks <= 10 => 3,  // Proyectos grandes: moderado
            default => 4                    // Proyectos masivos: conservador
        };
    }

    /**
     * ✅ ESTIMACIÓN DE JOBS CONCURRENTES MÁXIMOS
     */
    private function getMaxConcurrentJobs(): int
    {
        // ✅ Con 32GB RAM y 8 vCPUs podemos ser más agresivos
        return match(true) {
            $this->totalChunks <= 3 => 25,  // Proyectos pequeños: muy agresivo
            $this->totalChunks <= 8 => 20,  // Proyectos medianos: agresivo
            $this->totalChunks <= 15 => 15, // Proyectos grandes: moderado
            default => 12                   // Proyectos masivos: conservador
        };
    }

    /**
     * ✅ CÁLCULO DE DELAY POR JOB
     */
    private function calculateJobDelay(int $index, int $baseDelay, int $totalJobs): int
    {
        // ✅ Para los primeros jobs, delay mínimo
        if ($index < 5) {
            return $baseDelay;
        }

        // ✅ Delay progresivo pero más agresivo
        $progressiveDelay = floor($index / 5) * $baseDelay;

        // ✅ Máximo delay de 30 segundos
        return min($progressiveDelay, 30);
    }

    /**
     * ✅ VERIFICAR SI ES EL ÚLTIMO CHUNK
     */
    private function isLastChunk(): bool
    {
        return $this->chunkIndex === $this->totalChunks;
    }

    /**
     * ✅ PROGRAMAR VERIFICACIÓN DE COMPLETADO
     */
    private function scheduleCompletionCheck(AnalysisBatch $batch, int $validImageCount): void
    {
        // ✅ Tiempo estimado más optimista
        $estimatedTimePerImage = match(true) {
            $validImageCount <= 50 => 8,   // 8 segundos por imagen
            $validImageCount <= 200 => 10, // 10 segundos por imagen
            $validImageCount <= 500 => 12, // 12 segundos por imagen
            default => 15                  // 15 segundos por imagen
        };

        $totalEstimatedTime = $validImageCount * $estimatedTimePerImage;
        $bufferTime = min(120, $totalEstimatedTime * 0.3); // Buffer 30% con máximo 2 minutos
        $checkDelay = $totalEstimatedTime + $bufferTime;

        Log::info("⏰ Programando verificación de completado", [
            'batch_id' => $batch->id,
            'estimated_time' => $totalEstimatedTime,
            'buffer_time' => $bufferTime,
            'check_delay' => $checkDelay
        ]);

        CheckBatchCompletionJob::dispatch($batch->id, $this->notifyEmail)
            ->delay(now()->addSeconds($checkDelay))
            ->onQueue('default');
    }

    /**
     * ✅ MANEJO DE FALLOS MEJORADO
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessBulkImagesJob FAILED", [
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
            'attempt' => $this->attempts(),
            'chunk_size' => count($this->imageIds)
        ]);

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);

            if ($batch) {
                // ✅ Solo marcar como fallido si es un chunk crítico o el último
                $shouldMarkAsFailed = $this->isLastChunk() ||
                    ($this->chunkIndex <= 2 && $this->totalChunks <= 5);

                if ($shouldMarkAsFailed) {
                    Log::critical("💀 Chunk crítico {$this->chunkIndex} falló, marcando batch {$batch->id} como fallido");
                    $batch->update(['status' => 'failed']);
                } else {
                    Log::warning("⚠️ Chunk {$this->chunkIndex} falló pero batch {$batch->id} continúa");
                    $batch->touch(); // Solo actualizar timestamp
                }
            }
        }
    }
}
