<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use App\Models\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAnalysisChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutos por chunk
    public $tries = 3;
    public $maxExceptions = 3;

    // ✅ Backoff progresivo para Azure API rate limiting
    public function backoff(): array
    {
        return [60, 300, 900]; // 1min, 5min, 15min
    }

    public function __construct(
        public array $imageIds,
        public int $batchId,
        public int $chunkIndex,
        public int $totalChunks
    ) {
        $this->onQueue('analysis');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ ProcessAnalysisChunkJob: Batch {$this->batchId} no encontrado");
            return;
        }

        if ($batch->isCancelled()) {
            $batch->logInfo("Chunk de análisis cancelado - batch en estado: {$batch->status}");
            $batch->decrementActiveJobs();
            return;
        }

        $chunkSize = count($this->imageIds);
        $batch->logInfo("🤖 Iniciando chunk {$this->chunkIndex}/{$this->totalChunks} de análisis IA para {$chunkSize} imágenes");

        try {
            // ✅ Validar que las imágenes existen y están procesadas
            $validImages = $this->validateImages($this->imageIds, $batch);

            if ($validImages->isEmpty()) {
                throw new \Exception("No hay imágenes válidas para analizar en este chunk");
            }

            // ✅ Procesar cada imagen del chunk
            $processed = 0;
            $failed = 0;

            foreach ($validImages as $image) {
                try {
                    // ✅ Verificar cancelación antes de cada análisis
                    $batch->refresh();
                    if ($batch->isCancelled()) {
                        $batch->logInfo("Chunk cancelado durante procesamiento");
                        break;
                    }

                    $this->analyzeImage($image, $batch);
                    $processed++;

                    // ✅ Delay entre análisis para respetar rate limits
                    if ($processed < $validImages->count()) {
                        sleep(3); // 3 segundos entre análisis
                    }

                } catch (\Throwable $e) {
                    $failed++;
                    $batch->logError("Error analizando imagen {$image->id}: " . $e->getMessage());
                }
            }

            // ✅ Actualizar progreso del batch
            if ($processed > 0) {
                for ($i = 0; $i < $processed; $i++) {
                    $batch->incrementProcessed();
                }
            }

            if ($failed > 0) {
                for ($i = 0; $i < $failed; $i++) {
                    $batch->incrementFailed("Error en análisis IA");
                }
            }

            // ✅ Finalizar chunk
            $batch->decrementActiveJobs();
            $batch->logInfo("✅ Chunk {$this->chunkIndex} completado: {$processed} exitosas, {$failed} fallidas");

            // ✅ Enviar notificación si es el último chunk
            if ($this->chunkIndex === $this->totalChunks) {
                $this->sendCompletionNotification($batch);
            }

        } catch (\Throwable $e) {
            $batch->logError("Error en chunk de análisis {$this->chunkIndex}: " . $e->getMessage());
            $this->handleChunkFailure($batch, $e);
        }
    }

    /**
     * ✅ Validar que las imágenes están listas para análisis (adaptado)
     */
    private function validateImages(array $imageIds, UnifiedBatch $batch): \Illuminate\Database\Eloquent\Collection
    {
        // ✅ Buscar imágenes que estén procesadas
        $images = Image::with(['processedImage', 'analysisResult'])
            ->whereIn('id', $imageIds)
            ->where('is_processed', true) // ✅ Usar flag directo
            ->get();

        $validCount = $images->count();
        $totalRequested = count($imageIds);

        if ($validCount < $totalRequested) {
            $batch->logWarning("Solo {$validCount}/{$totalRequested} imágenes están listas para análisis");
        }

        return $images;
    }

    /**
     * 🔬 Analizar imagen individual (adaptado a estructura actual)
     */
    private function analyzeImage(Image $image, UnifiedBatch $batch): bool
    {
        if (!$image->is_processed) {
            throw new \Exception("Imagen {$image->id} no está procesada");
        }

        // ✅ Verificar si ya tiene análisis reciente
        if ($this->hasRecentAnalysis($image)) {
            $batch->logInfo("🔄 Imagen {$image->id} ya tiene análisis reciente, omitiendo");
            return true;
        }

        $batch->logInfo("🔬 Analizando imagen {$image->id}");

        try {
            $analysisResult = $this->simulateAzureAnalysis($image);

            // ✅ Si existe relación analysisResult, usarla
            if (method_exists($image, 'analysisResult')) {
                if ($image->analysisResult) {
                    $image->analysisResult->update([
                        'rows' => $analysisResult['rows'] ?? null,
                        'columns' => $analysisResult['columns'] ?? null,
                        'integrity_score' => $analysisResult['integrity_score'] ?? null,
                        'luminosity_score' => $analysisResult['luminosity_score'] ?? null,
                        'uniformity_score' => $analysisResult['uniformity_score'] ?? null,
                    ]);
                } else {
                    $image->analysisResult()->create([
                        'rows' => $analysisResult['rows'] ?? null,
                        'columns' => $analysisResult['columns'] ?? null,
                        'integrity_score' => $analysisResult['integrity_score'] ?? null,
                        'luminosity_score' => $analysisResult['luminosity_score'] ?? null,
                        'uniformity_score' => $analysisResult['uniformity_score'] ?? null,
                    ]);
                }
            }

            // ✅ Si existe relación processedImage, actualizar con AI response
            if (method_exists($image, 'processedImage') && $image->processedImage) {
                $image->processedImage->update([
                    'ai_response_json' => json_encode($analysisResult),
                    'analyzed_at' => now()
                ]);
            }

            // ✅ Actualizar timestamp para indicar análisis reciente
            $image->update(['processed_at' => now()]);

            $batch->logInfo("✅ Análisis completado para imagen {$image->id}");
            return true;

        } catch (\Throwable $e) {
            throw new \Exception("Error en análisis IA para imagen {$image->id}: " . $e->getMessage());
        }
    }

    /**
     * ✅ Verificar si tiene análisis reciente (adaptado a estructura actual)
     */
    private function hasRecentAnalysis(Image $image): bool
    {
        // ✅ Si existe relación processedImage, verificar analyzed_at
        if (method_exists($image, 'processedImage') && $image->processedImage) {
            $analyzedAt = $image->processedImage->analyzed_at ?? null;
            if ($analyzedAt && $analyzedAt->diffInHours(now()) < 24) {
                return true;
            }
        }

        // ✅ Fallback: usar processed_at si es muy reciente (menos de 2 horas)
        if ($image->processed_at && $image->processed_at->diffInHours(now()) < 2) {
            return true;
        }

        return false;
    }

    /**
     * 🎭 Simular análisis de Azure (temporal)
     */
    private function simulateAzureAnalysis(Image $image): array
    {
        // ✅ Simular tiempo de procesamiento realista
        $processingTime = rand(2, 8); // 2-8 segundos
        sleep($processingTime);

        // ✅ Generar datos realistas basados en el tipo de panel
        $baseRows = rand(6, 12);
        $baseCols = rand(8, 15);

        // ✅ Simular problemas ocasionales
        $hasProblems = rand(1, 100) <= 15; // 15% de probabilidad de problemas

        return [
            'analyzed_at' => now()->toISOString(),
            'processing_time_seconds' => $processingTime,
            'simulation_mode' => true,
            'image_id' => $image->id,
            'analysis_version' => '2.0_simulated',

            // ✅ Datos de estructura
            'rows' => $baseRows,
            'columns' => $baseCols,
            'total_cells' => $baseRows * $baseCols,

            // ✅ Métricas de calidad
            'integrity_score' => $hasProblems ? rand(40, 70) : rand(80, 95),
            'luminosity_score' => $hasProblems ? rand(30, 60) : rand(70, 90),
            'uniformity_score' => $hasProblems ? rand(35, 65) : rand(75, 90),

            // ✅ Problemas detectados
            'problems_detected' => $hasProblems ? [
                'type' => rand(1, 3) === 1 ? 'cell_damage' : (rand(1, 2) === 1 ? 'shading' : 'discoloration'),
                'severity' => rand(1, 3) === 1 ? 'low' : (rand(1, 2) === 1 ? 'medium' : 'high'),
                'affected_cells' => rand(1, 5)
            ] : null,

            // ✅ Metadata
            'confidence' => rand(85, 98),
            'azure_request_id' => 'sim_' . uniqid(),
        ];
    }

    /**
     * 📧 Enviar notificación de finalización
     */
    private function sendCompletionNotification(UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $notifyEmail = $config['notify_email'] ?? null;

        if (!$notifyEmail) {
            return;
        }

        try {
            // TODO: Implementar envío de email real
            $batch->logInfo("📧 Enviando notificación de finalización a: {$notifyEmail}");

            // Placeholder: programar job de email
            // SendAnalysisCompletedEmail::dispatch($batch->id, $notifyEmail)
            //     ->onQueue('default');

        } catch (\Throwable $e) {
            $batch->logError("Error enviando notificación: " . $e->getMessage());
        }
    }

    /**
     * 💥 Manejar fallo del chunk
     */
    private function handleChunkFailure(UnifiedBatch $batch, \Throwable $e): void
    {
        // ✅ Marcar todas las imágenes del chunk como fallidas
        $failedCount = count($this->imageIds);

        for ($i = 0; $i < $failedCount; $i++) {
            $batch->incrementFailed("Chunk {$this->chunkIndex} falló: " . $e->getMessage());
        }

        $batch->decrementActiveJobs();
    }

    /**
     * ✅ Manejo de fallos del job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessAnalysisChunkJob FAILED", [
            'batch_id' => $this->batchId,
            'chunk_index' => $this->chunkIndex,
            'total_chunks' => $this->totalChunks,
            'image_count' => count($this->imageIds),
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $this->handleChunkFailure($batch, $exception);
        }
    }

    /**
     * ⏰ Manejo de timeout
     */
    public function timeoutJob(): void
    {
        Log::error("⏰ ProcessAnalysisChunkJob TIMEOUT", [
            'batch_id' => $this->batchId,
            'chunk_index' => $this->chunkIndex,
            'image_count' => count($this->imageIds)
        ]);

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("Timeout en chunk de análisis {$this->chunkIndex}");
        }
    }
}
