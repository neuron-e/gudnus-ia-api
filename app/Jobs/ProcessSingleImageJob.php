<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use App\Models\Image;
use App\Services\ImageProcessingService;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessSingleImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutos por imagen
    public $tries = 3;
    public $maxExceptions = 3;

    // ✅ Backoff exponencial para reintentos
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    public function __construct(
        public int $imageId,
        public string $operation, // 'crop' | 'analyze' | 'both'
        public int $batchId
    ) {
        $this->onQueue('atomic-images');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);
        $image = Image::find($this->imageId);

        if (!$batch) {
            Log::error("❌ ProcessSingleImageJob: Batch {$this->batchId} no encontrado");
            return;
        }

        if (!$image) {
            $batch->logError("Imagen {$this->imageId} no encontrada");
            $batch->incrementFailed("Imagen {$this->imageId} no encontrada");
            return;
        }

        // ✅ Verificar si el batch fue cancelado
        if ($batch->isCancelled()) {
            $batch->logInfo("Job cancelado - batch en estado: {$batch->status}");
            $batch->decrementActiveJobs(); // Solo decrementar, no contar como procesado
            return;
        }

        $batch->logInfo("🖼️ Procesando imagen {$this->imageId} - operación: {$this->operation}");

        try {
            $startTime = microtime(true);

            // ✅ Procesar según la operación especificada
            $result = match($this->operation) {
                'crop' => $this->processCrop($image, $batch),
                'analyze' => $this->processAnalysis($image, $batch),
                'both' => $this->processBoth($image, $batch),
                default => throw new \InvalidArgumentException("Operación no soportada: {$this->operation}")
            };

            $processingTime = round((microtime(true) - $startTime) * 1000); // ms

            if ($result) {
                $batch->incrementProcessed();
                $batch->logInfo("✅ Imagen {$this->imageId} procesada exitosamente ({$processingTime}ms)");
            } else {
                $batch->incrementFailed("Procesamiento retornó resultado vacío");
                $batch->logError("❌ Imagen {$this->imageId} - procesamiento falló sin excepción");
            }

        } catch (\Throwable $e) {
            $batch->incrementFailed("Error: " . $e->getMessage());
            $batch->logError("❌ Error procesando imagen {$this->imageId}: " . $e->getMessage());

            // Re-lanzar excepción para activar reintentos si corresponde
            throw $e;
        }
    }

    /**
     * 🔧 PROCESAR: Solo recorte (adaptado a estructura actual)
     */
    private function processCrop(Image $image, UnifiedBatch $batch): bool
    {
        $imageProcessingService = app(ImageProcessingService::class);

        try {
            $processedImage = $imageProcessingService->process($image, $this->batchId);

            if (!$processedImage || $processedImage->status === 'error') {
                throw new \Exception("ImageProcessingService retornó error o null");
            }

            // ✅ Actualizar flags en la tabla images
            $image->update([
                'is_processed' => true,
                'processed_at' => now(),
                'status' => 'completed'
            ]);

            return true;

        } catch (\Throwable $e) {
            $image->update(['status' => 'error']);
            throw new \Exception("Error en recorte: " . $e->getMessage());
        }
    }

    /**
     * 🤖 PROCESAR: Solo análisis IA (adaptado a estructura actual)
     */
    private function processAnalysis(Image $image, UnifiedBatch $batch): bool
    {
        // ✅ Verificar que la imagen ya esté procesada
        if (!$image->is_processed) {
            throw new \Exception("La imagen debe estar procesada antes del análisis");
        }

        try {
            $batch->logInfo("🤖 Simulando análisis IA para imagen {$this->imageId}");

            // Simular tiempo de procesamiento
            sleep(2);

            // Simular resultado de análisis
            $analysisResult = [
                'processed_at' => now()->toISOString(),
                'simulated' => true,
                'rows' => rand(6, 12),
                'columns' => rand(8, 15),
                'integrity_score' => rand(70, 95),
                'luminosity_score' => rand(60, 90),
                'uniformity_score' => rand(65, 85)
            ];

            // ✅ Si existe relación analysisResult, usarla
            if (method_exists($image, 'analysisResult')) {
                if ($image->analysisResult) {
                    $image->analysisResult->update([
                        'rows' => $analysisResult['rows'],
                        'columns' => $analysisResult['columns'],
                        'integrity_score' => $analysisResult['integrity_score'],
                        'luminosity_score' => $analysisResult['luminosity_score'],
                        'uniformity_score' => $analysisResult['uniformity_score']
                    ]);
                } else {
                    $image->analysisResult()->create([
                        'rows' => $analysisResult['rows'],
                        'columns' => $analysisResult['columns'],
                        'integrity_score' => $analysisResult['integrity_score'],
                        'luminosity_score' => $analysisResult['luminosity_score'],
                        'uniformity_score' => $analysisResult['uniformity_score']
                    ]);
                }
            }

            // ✅ Si existe relación processedImage, actualizar con AI response
            if (method_exists($image, 'processedImage') && $image->processedImage) {
                $image->processedImage->update([
                    'ai_response_json' => json_encode($analysisResult)
                ]);
            }

            // ✅ Actualizar timestamp de análisis
            $image->update([
                'processed_at' => now() // Actualizar para indicar análisis reciente
            ]);

            return true;

        } catch (\Throwable $e) {
            throw new \Exception("Error en análisis IA: " . $e->getMessage());
        }
    }

    /**
     * 🔄 PROCESAR: Recorte + Análisis
     */
    private function processBoth(Image $image, UnifiedBatch $batch): bool
    {
        // ✅ Primero recorte
        $cropResult = $this->processCrop($image, $batch);

        if (!$cropResult) {
            throw new \Exception("Falló el recorte, no se puede continuar con análisis");
        }

        // ✅ Esperar un momento para que se complete el recorte
        sleep(1);

        // ✅ Luego análisis
        $image->refresh(); // Refresh para obtener processedImage actualizada
        return $this->processAnalysis($image, $batch);
    }

    /**
     * ✅ Manejo de fallos con información detallada
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessSingleImageJob FAILED", [
            'image_id' => $this->imageId,
            'batch_id' => $this->batchId,
            'operation' => $this->operation,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $errorMessage = "Imagen {$this->imageId} falló después de {$this->attempts()} intentos: " . $exception->getMessage();

            // ✅ Solo incrementar failed si no se ha hecho ya
            if (!$this->hasAlreadyFailed()) {
                $batch->incrementFailed($errorMessage);
            }
        }

        // ✅ Marcar imagen como fallida
        $image = Image::find($this->imageId);
        if ($image) {
            $image->update(['status' => 'error']);
        }
    }

    /**
     * 🔍 Verificar si ya se contabilizó como fallida (evitar doble conteo)
     */
    private function hasAlreadyFailed(): bool
    {
        // ✅ Si es el último intento, aún no se ha contabilizado
        return $this->attempts() < $this->tries;
    }

    /**
     * ⏰ Manejo de timeout específico
     */
    public function timeoutJob(): void
    {
        Log::error("⏰ ProcessSingleImageJob TIMEOUT", [
            'image_id' => $this->imageId,
            'batch_id' => $this->batchId,
            'operation' => $this->operation
        ]);

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("Timeout procesando imagen {$this->imageId} (operación: {$this->operation})");
        }
    }

    /**
     * 🔄 Determinar si debe reintentarse
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        // ✅ No reintentar si el batch fue cancelado
        $batch = UnifiedBatch::find($this->batchId);
        if ($batch && $batch->isCancelled()) {
            Log::info("No reintentando job - batch cancelado");
            return false;
        }

        // ✅ No reintentar errores de configuración
        if (str_contains($exception->getMessage(), 'Operación no soportada')) {
            return false;
        }

        // ✅ No reintentar si la imagen no existe
        if (str_contains($exception->getMessage(), 'no encontrada')) {
            return false;
        }

        // ✅ Reintentar para otros errores
        return true;
    }
}
