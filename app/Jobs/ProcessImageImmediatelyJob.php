<?php

namespace App\Jobs;

use App\Models\AnalysisBatch;
use App\Models\ImageAnalysisResult;
use App\Models\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

class ProcessImageImmediatelyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA SERVIDOR POTENTE
    public $timeout = 300;      // ✅ 5 minutos por imagen (era 180)
    public $tries = 5;          // ✅ Más reintentos (era 4)
    public $maxExceptions = 3;

    // ✅ CONSTANTES OPTIMIZADAS
    private const AZURE_MAX_SIZE_BYTES = 4 * 1024 * 1024; // 4MB
    private const SAFETY_MARGIN = 0.85; // ✅ Margen más conservador (era 0.8)
    private const DEFAULT_QUALITIES = [90, 80, 70, 60, 50, 40, 30, 20]; // ✅ Más opciones
    private const MIN_DIMENSION = 300;
    private const MAX_CONCURRENT_REQUESTS = 15; // ✅ Límite de requests simultáneos Azure

    /**
     * ✅ BACKOFF OPTIMIZADO - Más agresivo al principio
     */
    public function backoff(): array
    {
        return [
            5,    // ✅ 5s primer reintento (era 10s)
            15,   // ✅ 15s segundo reintento (era 30s)
            45,   // ✅ 45s tercer reintento (era 90s)
            120,  // ✅ 2min cuarto reintento (era 300s)
            300   // ✅ 5min quinto reintento (nuevo)
        ];
    }

    public function __construct(
        public int $imageId,
        public ?int $batchId = null
    ) {}

    public function handle()
    {
        $startTime = microtime(true);
        $attemptNumber = $this->attempts();

        Log::info("🤖 [INTENTO {$attemptNumber}] Iniciando análisis IA para imagen {$this->imageId}", [
            'batch_id' => $this->batchId,
            'attempt' => $attemptNumber,
            'memory_start' => memory_get_usage(true) / 1024 / 1024 . 'MB'
        ]);

        $image = Image::with(['processedImage', 'analysisResult'])->find($this->imageId);
        if (!$image) {
            Log::error("❌ Imagen {$this->imageId} no encontrada");
            return;
        }

        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        try {
            // ✅ VERIFICACIÓN PREVIA OPTIMIZADA
            if (!$this->validateImageForProcessing($image)) {
                $this->handleProcessingError($batch, "Imagen no válida para procesamiento");
                return;
            }

            // ✅ PROCESAR CON AZURE
            $result = $this->processImageWithAI($image);

            if ($result) {
                $processingTime = round(microtime(true) - $startTime, 2);

                Log::info("✅ [ÉXITO] Imagen {$this->imageId} analizada correctamente", [
                    'attempt' => $attemptNumber,
                    'processing_time' => $processingTime . 's',
                    'batch_id' => $this->batchId,
                    'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
                ]);

                // ✅ ACTUALIZAR BATCH DE FORMA THREAD-SAFE
                if ($batch) {
                    $this->updateBatchProgress($batch);
                }
            } else {
                throw new \Exception("processImageWithAI retornó false");
            }

        } catch (\Throwable $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ [ERROR] Imagen {$this->imageId} falló en intento {$attemptNumber}", [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime . 's',
                'batch_id' => $this->batchId,
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

            $this->handleProcessingError($batch, $e->getMessage());
        }
    }

    /**
     * ✅ VALIDACIÓN PREVIA MEJORADA
     */
    private function validateImageForProcessing(Image $image): bool
    {
        // Verificar que tiene imagen procesada
        if (!$image->processedImage || !$image->processedImage->corrected_path) {
            Log::warning("⚠️ Imagen {$image->id}: no recortada");
            return false;
        }

        // Verificar que no está ya procesada
        if ($image->is_processed) {
            Log::debug("ℹ️ Imagen {$image->id} ya procesada con IA");
            return false;
        }

        // Verificar que existe en Wasabi
        if (!Storage::disk('wasabi')->exists($image->processedImage->corrected_path)) {
            Log::warning("⚠️ Imagen {$image->id}: archivo no existe en Wasabi");
            return false;
        }

        return true;
    }

    /**
     * ✅ PROCESAMIENTO CON AZURE OPTIMIZADO
     */
    private function processImageWithAI(Image $image): bool
    {
        $correctedPath = $image->processedImage->corrected_path;

        try {
            // ✅ VALIDACIÓN DE TAMAÑO
            $validation = $this->validateImageForAzure($correctedPath);
            if (!$validation['valid']) {
                if (isset($validation['error'])) {
                    throw new \Exception("Error validando: " . $validation['error']);
                }
            }

            // ✅ PREPARAR IMAGEN
            $imageContent = Storage::disk('wasabi')->get($correctedPath);
            $processedImageContent = $this->prepareImageForAzure($imageContent);

            Log::debug("🤖 Enviando a Azure", [
                'image_id' => $image->id,
                'original_size' => $this->formatBytes(strlen($imageContent)),
                'processed_size' => $this->formatBytes(strlen($processedImageContent)),
                'attempt' => $this->attempts()
            ]);

            // ✅ LLAMADA A AZURE CON RETRY INTELIGENTE
            $response = $this->makeAzureRequest($processedImageContent, $image->id);

            if (!$response->successful()) {
                return $this->handleAzureError($response, $image);
            }

            $json = $response->json();
            if (!$json || !isset($json['predictions'])) {
                throw new \Exception("Respuesta Azure inválida");
            }

            // ✅ GUARDAR RESULTADOS
            return $this->saveAnalysisResults($image, $json);

        } catch (\Throwable $e) {
            Log::error("❌ Error análisis IA imagen {$image->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ LLAMADA A AZURE CON RETRY MEJORADO
     */
    private function makeAzureRequest(string $imageContent, int $imageId)
    {
        $maxRetries = 3;
        $baseDelay = 1; // segundos

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                $response = Http::timeout(90)
                    ->withHeaders([
                        'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                        'Content-Type' => 'application/octet-stream',
                    ])
                    ->withBody($imageContent, 'application/octet-stream')
                    ->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

                // ✅ Si es exitoso, retornar inmediatamente
                if ($response->successful()) {
                    if ($retry > 0) {
                        Log::info("✅ Azure request exitoso en reintento {$retry} para imagen {$imageId}");
                    }
                    return $response;
                }

                // ✅ MANEJAR RATE LIMITING
                if ($response->status() === 429) {
                    $retryAfter = (int)$response->header('Retry-After', 60);
                    $delaySeconds = max($retryAfter, $baseDelay * pow(2, $retry));

                    if ($retry < $maxRetries) {
                        Log::warning("⚠️ Rate limit Azure imagen {$imageId}, esperando {$delaySeconds}s (reintento {$retry})");
                        sleep($delaySeconds);
                        continue;
                    }
                }

                // ✅ ERRORES 5XX - RETRY
                if ($response->status() >= 500 && $retry < $maxRetries) {
                    $delaySeconds = $baseDelay * pow(2, $retry);
                    Log::warning("⚠️ Error Azure {$response->status()} imagen {$imageId}, reintentando en {$delaySeconds}s");
                    sleep($delaySeconds);
                    continue;
                }

                // ✅ Si llegamos aquí, es un error no recoverable
                break;

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($retry < $maxRetries) {
                    $delaySeconds = $baseDelay * pow(2, $retry);
                    Log::warning("⚠️ Error conexión Azure imagen {$imageId}, reintentando en {$delaySeconds}s");
                    sleep($delaySeconds);
                    continue;
                }
                throw $e;
            }
        }

        return $response;
    }

    /**
     * ✅ MANEJO DE ERRORES AZURE MEJORADO
     */
    private function handleAzureError($response, Image $image): bool
    {
        $statusCode = $response->status();
        $responseBody = substr($response->body(), 0, 500);

        Log::error("❌ Azure error imagen {$image->id}", [
            'status' => $statusCode,
            'body' => $responseBody,
            'attempt' => $this->attempts()
        ]);

        // ✅ RATE LIMITING - Delegar al backoff del job
        if ($statusCode === 429) {
            $retryAfter = (int)$response->header('Retry-After', 60);
            Log::warning("⚠️ Rate limit imagen {$image->id}, será reintentado automáticamente");

            if ($this->attempts() < $this->tries) {
                // Liberar job para reintento automático
                $this->release(now()->addSeconds($retryAfter + rand(5, 15)));
                return false;
            }
        }

        // ✅ ERRORES TEMPORALES 5XX
        if ($statusCode >= 500) {
            throw new \Exception("Azure server error {$statusCode}");
        }

        // ✅ ERRORES CLIENTE 4XX - No reintentar
        Log::error("💀 Error cliente Azure {$statusCode} imagen {$image->id}, no reintentando");
        return false;
    }

    /**
     * ✅ ACTUALIZACIÓN THREAD-SAFE DEL BATCH
     */
    private function updateBatchProgress(AnalysisBatch $batch): void
    {
        try {
            \DB::transaction(function() use ($batch) {
                $batch->increment('processed_images');
                $batch->touch();
            });

            // ✅ LOG DE PROGRESO CADA 25 IMÁGENES
            if ($batch->processed_images % 25 === 0) {
                $progress = round(($batch->processed_images / $batch->total_images) * 100, 1);
                Log::info("📊 Progreso batch {$batch->id}: {$batch->processed_images}/{$batch->total_images} ({$progress}%)");
            }

        } catch (\Exception $e) {
            Log::warning("⚠️ Error actualizando progreso batch: " . $e->getMessage());
        }
    }

    /**
     * ✅ PREPARACIÓN DE IMAGEN OPTIMIZADA
     */
    private function prepareImageForAzure(string $imageContent): string
    {
        $currentSize = strlen($imageContent);

        if ($currentSize <= self::AZURE_MAX_SIZE_BYTES) {
            return $imageContent;
        }

        Log::info("🔄 Redimensionando imagen: " . $this->formatBytes($currentSize));

        try {
            return $this->resizeWithIntervention($imageContent);
        } catch (\Exception $e) {
            Log::warning("⚠️ Intervention falló: " . $e->getMessage());
            try {
                return $this->resizeWithGD($imageContent);
            } catch (\Exception $gdException) {
                throw new \Exception("Redimensionamiento falló: " . $e->getMessage());
            }
        }
    }

    /**
     * ✅ REDIMENSIONAMIENTO CON INTERVENTION MEJORADO
     */
    private function resizeWithIntervention(string $imageContent): string
    {
        $manager = new ImageManager(new GdDriver());
        $image = $manager->read($imageContent);

        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // ✅ ESTRATEGIA 1: Solo compresión
        foreach ([85, 75, 65, 55, 45, 35, 25, 15] as $quality) {
            $encoded = $image->toJpeg($quality);
            $newSize = strlen($encoded);

            if ($newSize <= self::AZURE_MAX_SIZE_BYTES) {
                Log::info("✅ Redimensionado solo compresión: {$this->formatBytes($newSize)} (Q{$quality}%)");
                return $encoded;
            }
        }

        // ✅ ESTRATEGIA 2: Reducir dimensiones
        $targetSize = (int)(self::AZURE_MAX_SIZE_BYTES * self::SAFETY_MARGIN);
        $reductionFactor = sqrt($targetSize / strlen($imageContent));
        $reductionFactor = max($reductionFactor, 0.25); // Mínimo 25%

        $newWidth = max((int)($originalWidth * $reductionFactor), self::MIN_DIMENSION);
        $newHeight = max((int)($originalHeight * $reductionFactor), self::MIN_DIMENSION);

        $resizedImage = $image->resize($newWidth, $newHeight);

        foreach (self::DEFAULT_QUALITIES as $quality) {
            $encoded = $resizedImage->toJpeg($quality);
            $newSize = strlen($encoded);

            if ($newSize <= self::AZURE_MAX_SIZE_BYTES) {
                Log::info("✅ Redimensionado: {$this->formatBytes($newSize)} (Q{$quality}%, {$newWidth}x{$newHeight})");
                return $encoded;
            }
        }

        throw new \Exception("No se pudo reducir suficientemente");
    }

    /**
     * ✅ VALIDACIÓN DE IMAGEN PARA AZURE
     */
    private function validateImageForAzure(string $correctedPath): array
    {
        try {
            if (!Storage::disk('wasabi')->exists($correctedPath)) {
                return ['valid' => false, 'error' => 'Archivo no existe'];
            }

            $size = Storage::disk('wasabi')->size($correctedPath);

            return [
                'valid' => $size <= self::AZURE_MAX_SIZE_BYTES,
                'size' => $size,
                'needs_resize' => $size > self::AZURE_MAX_SIZE_BYTES,
                'size_formatted' => $this->formatBytes($size)
            ];

        } catch (\Exception $e) {
            return ['valid' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * ✅ GUARDADO DE RESULTADOS OPTIMIZADO
     */
    private function saveAnalysisResults(Image $image, array $json): bool
    {
        try {
            \DB::transaction(function() use ($image, $json) {
                // ✅ Mapeo de tags a campos
                $mapping = [
                    'Microgrietas' => 'microcracks_count',
                    'Fingers' => 'finger_interruptions_count',
                    'Black Edges' => 'black_edges_count',
                    'Intensidad' => 'cells_with_different_intensity',
                ];

                $counts = [];
                foreach ($json['predictions'] as $prediction) {
                    $tag = $prediction['tagName'] ?? '';
                    if (isset($mapping[$tag])) {
                        $field = $mapping[$tag];
                        $counts[$field] = ($counts[$field] ?? 0) + 1;
                    }
                }

                // ✅ Guardar análisis
                $analysis = $image->analysisResult ?? new ImageAnalysisResult();
                $analysis->fill($counts);
                $image->analysisResult()->save($analysis);

                // ✅ Guardar JSON completo
                $image->processedImage->ai_response_json = json_encode($json);
                $image->processedImage->save();

                // ✅ Marcar como procesada
                $image->update(['is_processed' => true]);
            });

            Log::debug("✅ Resultados guardados imagen {$image->id}");
            return true;

        } catch (\Exception $e) {
            Log::error("❌ Error guardando resultados imagen {$image->id}: " . $e->getMessage());
            throw $e;
        }
    }

    // ✅ MÉTODOS AUXILIARES

    private function handleProcessingError(?AnalysisBatch $batch, string $error): void
    {
        if ($batch) {
            try {
                \DB::transaction(function() use ($batch) {
                    $batch->increment('errors');
                    $batch->touch();
                });
            } catch (\Exception $e) {
                Log::warning("Error actualizando errores batch: " . $e->getMessage());
            }
        }

        if ($this->attempts() >= $this->tries) {
            Log::critical("💀 Imagen {$this->imageId} falló definitivamente: {$error}");
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    private function resizeWithGD(string $imageContent): string
    {
        // ✅ Implementación GD optimizada similar pero más simple
        $sourceImage = imagecreatefromstring($imageContent);
        if (!$sourceImage) {
            throw new \Exception("GD no pudo crear imagen");
        }

        // Solo compresión primero
        foreach ([85, 75, 65, 55, 45, 35, 25, 15] as $quality) {
            ob_start();
            imagejpeg($sourceImage, null, $quality);
            $compressed = ob_get_clean();

            if (strlen($compressed) <= self::AZURE_MAX_SIZE_BYTES) {
                imagedestroy($sourceImage);
                return $compressed;
            }
        }

        // Redimensionar si la compresión no es suficiente
        $originalWidth = imagesx($sourceImage);
        $originalHeight = imagesy($sourceImage);

        $targetSize = (int)(self::AZURE_MAX_SIZE_BYTES * self::SAFETY_MARGIN);
        $reductionFactor = sqrt($targetSize / strlen($imageContent));
        $reductionFactor = max($reductionFactor, 0.25);

        $newWidth = max((int)($originalWidth * $reductionFactor), self::MIN_DIMENSION);
        $newHeight = max((int)($originalHeight * $reductionFactor), self::MIN_DIMENSION);

        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled(
            $resizedImage, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        foreach (self::DEFAULT_QUALITIES as $quality) {
            ob_start();
            imagejpeg($resizedImage, null, $quality);
            $encoded = ob_get_clean();

            if (strlen($encoded) <= self::AZURE_MAX_SIZE_BYTES) {
                imagedestroy($sourceImage);
                imagedestroy($resizedImage);
                return $encoded;
            }
        }

        imagedestroy($sourceImage);
        imagedestroy($resizedImage);
        throw new \Exception("GD no pudo reducir suficientemente");
    }

    /**
     * ✅ MANEJO DE FALLOS DEFINITIVOS
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessImageImmediatelyJob FAILED definitivamente", [
            'image_id' => $this->imageId,
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
        ]);

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);
            if ($batch) {
                try {
                    \DB::transaction(function() use ($batch) {
                        $errors = $batch->errors ?? 0;
                        $batch->update([
                            'errors' => $errors + 1,
                            'updated_at' => now()
                        ]);
                    });

                    $totalErrors = ($batch->errors ?? 0) + 1;
                    $errorRate = $totalErrors / $batch->total_images;

                    // ✅ Si hay demasiados errores, marcar batch como fallido
                    if ($errorRate > 0.25) { // 25% de error máximo
                        Log::critical("💀 Batch {$batch->id} con demasiados errores ({$errorRate}%), marcando como fallido");
                        $batch->update(['status' => 'failed']);
                    }

                } catch (\Exception $e) {
                    Log::error("Error actualizando errores en batch: " . $e->getMessage());
                }
            }
        }
    }
}
