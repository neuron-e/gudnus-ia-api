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
use Intervention\Image\Facades\Image as InterventionImage;

class ProcessImageImmediatelyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180; // 3 minutos por imagen
    public $tries = 4; // Más reintentos
    public $maxExceptions = 3;

    // Constantes para manejo de imágenes
    private const AZURE_MAX_SIZE_BYTES = 4 * 1024 * 1024; // 4MB
    private const SAFETY_MARGIN = 0.8; // Margen de seguridad (80% del límite)
    private const DEFAULT_QUALITIES = [85, 75, 65, 55, 45, 35, 25];
    private const MIN_DIMENSION = 300; // Dimensión mínima para mantener calidad

    /**
     * Backoff exponencial con jitter
     */
    public function backoff(): array
    {
        return [
            10 + rand(0, 10),   // 10-20s
            30 + rand(0, 30),   // 30-60s
            90 + rand(0, 60),   // 90-150s
            300 + rand(0, 120)  // 300-420s
        ];
    }

    public function __construct(
        public int $imageId,
        public ?int $batchId = null
    ) {}

    public function handle()
    {
        Log::info("🤖 Iniciando análisis IA para imagen {$this->imageId} (intento {$this->attempts()})");

        $image = Image::with(['processedImage', 'analysisResult'])->find($this->imageId);
        if (!$image) {
            Log::error("❌ Imagen {$this->imageId} no encontrada");
            return;
        }

        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        try {
            $result = $this->processImageWithAI($image);

            if ($result) {
                Log::info("✅ Imagen {$this->imageId} analizada exitosamente con IA");

                // Actualizar batch si existe
                if ($batch) {
                    $batch->increment('processed_images');
                    $batch->touch();

                    // Log de progreso cada 10 imágenes
                    if ($batch->processed_images % 10 === 0) {
                        $progress = round(($batch->processed_images / $batch->total_images) * 100, 1);
                        Log::info("📊 Progreso batch {$batch->id}: {$batch->processed_images}/{$batch->total_images} ({$progress}%)");
                    }
                }
            } else {
                Log::error("❌ Error analizando imagen {$this->imageId} con IA");
                $this->handleProcessingError($batch, "Error en processImageWithAI");
            }

        } catch (\Throwable $e) {
            Log::error("❌ Exception analizando imagen {$this->imageId}: " . $e->getMessage());
            $this->handleProcessingError($batch, $e->getMessage());
        }
    }

    /**
     * Maneja errores de procesamiento
     */
    private function handleProcessingError(?AnalysisBatch $batch, string $error): void
    {
        if ($batch) {
            $batch->touch(); // Solo actualizar timestamp
        }

        // Si es el último intento, loguear como error crítico
        if ($this->attempts() >= $this->tries) {
            Log::critical("💀 Imagen {$this->imageId} falló definitivamente después de {$this->attempts()} intentos: {$error}");
        }
    }

    /**
     * Valida si la imagen puede ser procesada por Azure
     */
    private function validateImageForAzure(string $correctedPath): array
    {
        try {
            // Obtener info del archivo
            $size = Storage::disk('wasabi')->size($correctedPath);

            return [
                'valid' => $size <= self::AZURE_MAX_SIZE_BYTES,
                'size' => $size,
                'max_size' => self::AZURE_MAX_SIZE_BYTES,
                'needs_resize' => $size > self::AZURE_MAX_SIZE_BYTES,
                'size_mb' => round($size / 1024 / 1024, 2)
            ];

        } catch (\Exception $e) {
            Log::error("❌ Error validando imagen: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Redimensiona la imagen si excede el límite de Azure usando Intervention Image
     */
    private function prepareImageForAzure(string $imageContent): string
    {
        $currentSize = strlen($imageContent);

        // Si ya está dentro del límite, devolver tal como está
        if ($currentSize <= self::AZURE_MAX_SIZE_BYTES) {
            Log::debug("✅ Imagen dentro del límite: " . $this->formatBytes($currentSize));
            return $imageContent;
        }

        Log::info("🔄 Redimensionando imagen: {$this->formatBytes($currentSize)} -> objetivo: {$this->formatBytes(self::AZURE_MAX_SIZE_BYTES)}");

        try {
            // Cargar imagen con Intervention Image
            $image = InterventionImage::make($imageContent);
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            Log::debug("📏 Dimensiones originales: {$originalWidth}x{$originalHeight}");

            // Calcular factor de reducción basado en el tamaño del archivo
            $targetSize = (int)(self::AZURE_MAX_SIZE_BYTES * self::SAFETY_MARGIN);
            $reductionFactor = sqrt($targetSize / $currentSize);

            // Asegurar que no se reduzca demasiado
            $reductionFactor = max($reductionFactor, 0.3); // Mínimo 30% del tamaño original

            $newWidth = max((int)($originalWidth * $reductionFactor), self::MIN_DIMENSION);
            $newHeight = max((int)($originalHeight * $reductionFactor), self::MIN_DIMENSION);

            Log::debug("🎯 Nuevas dimensiones calculadas: {$newWidth}x{$newHeight} (factor: " . round($reductionFactor, 3) . ")");

            // Redimensionar manteniendo proporción
            $resizedImage = $image->resize($newWidth, $newHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize(); // Prevenir agrandar
            });

            // Probar diferentes calidades hasta alcanzar el tamaño objetivo
            foreach (self::DEFAULT_QUALITIES as $quality) {
                $encoded = $resizedImage->encode('jpg', $quality)->__toString();
                $newSize = strlen($encoded);

                Log::debug("🧪 Calidad {$quality}%: {$this->formatBytes($newSize)}");

                if ($newSize <= self::AZURE_MAX_SIZE_BYTES) {
                    Log::info("✅ Imagen redimensionada exitosamente: {$this->formatBytes($currentSize)} -> {$this->formatBytes($newSize)} (calidad {$quality}%, {$newWidth}x{$newHeight})");
                    return $encoded;
                }
            }

            // Si aún es muy grande, reducir más agresivamente las dimensiones
            $aggressiveWidth = max((int)($newWidth * 0.6), self::MIN_DIMENSION);
            $aggressiveHeight = max((int)($newHeight * 0.6), self::MIN_DIMENSION);

            Log::warning("⚠️ Aplicando reducción agresiva: {$aggressiveWidth}x{$aggressiveHeight}");

            $finalImage = $image->resize($aggressiveWidth, $aggressiveHeight)->encode('jpg', 25);
            $finalSize = strlen($finalImage->__toString());

            if ($finalSize <= self::AZURE_MAX_SIZE_BYTES) {
                Log::info("✅ Imagen redimensionada con reducción agresiva: {$this->formatBytes($currentSize)} -> {$this->formatBytes($finalSize)}");
                return $finalImage->__toString();
            }

            throw new \Exception("No se pudo reducir la imagen lo suficiente. Tamaño final: {$this->formatBytes($finalSize)}");

        } catch (\Exception $e) {
            Log::error("❌ Error redimensionando imagen: " . $e->getMessage());

            // Fallback: intentar con GD si Intervention Image falla
            Log::info("🔄 Intentando fallback con GD...");
            return $this->prepareImageForAzureWithGD($imageContent);
        }
    }

    /**
     * Método de fallback usando GD (sin dependencias externas)
     */
    private function prepareImageForAzureWithGD(string $imageContent): string
    {
        $currentSize = strlen($imageContent);

        if ($currentSize <= self::AZURE_MAX_SIZE_BYTES) {
            return $imageContent;
        }

        Log::info("🔄 Redimensionando con GD: {$this->formatBytes($currentSize)}");

        try {
            $sourceImage = imagecreatefromstring($imageContent);
            if (!$sourceImage) {
                throw new \Exception("No se pudo crear imagen desde el contenido con GD");
            }

            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);

            // Calcular nuevas dimensiones
            $targetSize = (int)(self::AZURE_MAX_SIZE_BYTES * self::SAFETY_MARGIN);
            $reductionFactor = sqrt($targetSize / $currentSize);
            $reductionFactor = max($reductionFactor, 0.3);

            $newWidth = max((int)($originalWidth * $reductionFactor), self::MIN_DIMENSION);
            $newHeight = max((int)($originalHeight * $reductionFactor), self::MIN_DIMENSION);

            Log::debug("📏 GD: {$originalWidth}x{$originalHeight} -> {$newWidth}x{$newHeight}");

            // Crear nueva imagen
            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            // Preservar calidad
            if (function_exists('imagealphablending')) {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
            }

            // Redimensionar
            imagecopyresampled(
                $resizedImage, $sourceImage,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $originalWidth, $originalHeight
            );

            // Probar diferentes calidades
            foreach (self::DEFAULT_QUALITIES as $quality) {
                ob_start();
                imagejpeg($resizedImage, null, $quality);
                $encoded = ob_get_clean();
                $newSize = strlen($encoded);

                if ($newSize <= self::AZURE_MAX_SIZE_BYTES) {
                    Log::info("✅ Imagen redimensionada con GD: {$this->formatBytes($currentSize)} -> {$this->formatBytes($newSize)} (calidad {$quality}%)");

                    // Limpiar memoria
                    imagedestroy($sourceImage);
                    imagedestroy($resizedImage);

                    return $encoded;
                }
            }

            // Limpiar memoria
            imagedestroy($sourceImage);
            imagedestroy($resizedImage);

            throw new \Exception("No se pudo reducir la imagen lo suficiente con GD");

        } catch (\Exception $e) {
            Log::error("❌ Error con GD fallback: " . $e->getMessage());
            throw new \Exception("Error en ambos métodos de redimensionamiento: " . $e->getMessage());
        }
    }

    /**
     * Formatea bytes a formato legible
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    /**
     * Procesar una imagen con análisis IA de Azure (versión mejorada)
     */
    private function processImageWithAI(Image $image): bool
    {
        // Verificar que tiene imagen procesada (recortada)
        if (!$image->processedImage || !$image->processedImage->corrected_path) {
            Log::warning("⚠️ Imagen {$image->id}: no ha sido recortada, no se puede analizar con IA");
            return false;
        }

        $correctedPath = $image->processedImage->corrected_path;

        if (!Storage::disk('wasabi')->exists($correctedPath)) {
            Log::warning("⚠️ Imagen {$image->id}: archivo recortado no existe en Wasabi: {$correctedPath}");
            return false;
        }

        // Si ya está procesada con IA, saltarla
        if ($image->is_processed) {
            Log::debug("ℹ️ Imagen {$image->id} ya está procesada con IA");
            return true;
        }

        // Validación previa
        $validation = $this->validateImageForAzure($correctedPath);

        if (!$validation['valid'] && isset($validation['error'])) {
            Log::error("❌ Error validando imagen {$image->id}: " . $validation['error']);
            return false;
        }

        if ($validation['needs_resize']) {
            Log::info("⚠️ Imagen {$image->id} requiere redimensionamiento: {$validation['size_mb']}MB > 4MB");
        }

        try {
            // Obtener contenido de la imagen
            $imageContent = Storage::disk('wasabi')->get($correctedPath);

            // Preparar imagen para Azure (redimensionar si es necesario)
            $processedImageContent = $this->prepareImageForAzure($imageContent);

            Log::debug("🤖 Enviando imagen {$image->id} a Azure para análisis IA", [
                'file_path' => $correctedPath,
                'original_size' => $this->formatBytes(strlen($imageContent)),
                'processed_size' => $this->formatBytes(strlen($processedImageContent)),
                'attempt' => $this->attempts()
            ]);

            // Llamada a Azure con mejor manejo de errores
            $response = Http::timeout(90)
                ->retry(3, 1000, function ($exception, $request) {
                    // Retry en timeouts y errores 5xx
                    if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                        Log::warning("🔄 Reintentando por error de conexión: " . $exception->getMessage());
                        return true;
                    }

                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response?->status() ?? 0;
                        if ($status >= 500 || $status === 429) {
                            Log::warning("🔄 Reintentando por status {$status}");
                            return true;
                        }
                    }

                    return false;
                })
                ->withHeaders([
                    'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($processedImageContent, 'application/octet-stream')
                ->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

            if (!$response->successful()) {
                return $this->handleAzureError($response, $image);
            }

            $json = $response->json();
            if (!$json || !isset($json['predictions'])) {
                throw new \Exception("Respuesta de Azure inválida o vacía");
            }

            Log::debug("✅ Azure prediction response para imagen {$image->id}", [
                'predictions_count' => count($json['predictions'])
            ]);

            // Procesar y guardar resultados
            return $this->saveAnalysisResults($image, $json);

        } catch (\Throwable $e) {
            Log::error("❌ Error en análisis IA para imagen {$image->id}: " . $e->getMessage(), [
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Maneja errores específicos de Azure
     */
    private function handleAzureError($response, Image $image): bool
    {
        $statusCode = $response->status();
        $responseBody = $response->body();

        Log::error("❌ Azure prediction failed para imagen {$image->id}", [
            'status' => $statusCode,
            'body' => $responseBody,
            'attempt' => $this->attempts()
        ]);

        // Rate limiting específico
        if ($statusCode === 429) {
            $retryAfter = $response->header('Retry-After', 60);
            Log::warning("⚠️ Rate limit alcanzado para imagen {$image->id}, retry after: {$retryAfter}s");

            if ($this->attempts() < $this->tries) {
                $this->release(now()->addSeconds($retryAfter + rand(5, 15)));
                return false;
            } else {
                Log::error("💀 Rate limit agotado para imagen {$image->id} después de {$this->attempts()} intentos");
                return false;
            }
        }

        // Errores temporales de Azure (5xx)
        if ($statusCode >= 500) {
            throw new \Exception("Azure server error {$statusCode}: {$responseBody}");
        }

        // Errores de cliente (4xx) - no reintentar
        Log::error("💀 Error cliente Azure {$statusCode} para imagen {$image->id}, no reintentando");
        return false;
    }

    /**
     * Guarda los resultados del análisis
     */
    private function saveAnalysisResults(Image $image, array $json): bool
    {
        try {
            // Mapeo de tags a campos de base de datos
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

            // Guardar resultados del análisis
            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill($counts);
            $image->analysisResult()->save($analysis);

            // Guardar respuesta JSON completa
            $image->processedImage->ai_response_json = json_encode($json);
            $image->processedImage->save();

            // Marcar como procesada con IA
            $image->update(['is_processed' => true]);

            Log::debug("✅ Análisis IA guardado para imagen {$image->id}", $counts);
            return true;

        } catch (\Exception $e) {
            Log::error("❌ Error guardando resultados para imagen {$image->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Maneja fallos definitivos del job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessImageImmediatelyJob falló definitivamente para imagen {$this->imageId}: " . $exception->getMessage());

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);
            if ($batch) {
                // Incrementar contador de errores
                $errors = $batch->errors ?? 0;
                $batch->update([
                    'errors' => $errors + 1,
                    'updated_at' => now()
                ]);

                $totalErrors = $errors + 1;
                $errorRate = $totalErrors / $batch->total_images;

                // Si hay demasiados errores (>20%), marcar como fallido
                if ($errorRate > 0.2) {
                    Log::critical("💀 Batch {$batch->id} tiene demasiados errores ({$errorRate}%), marcando como fallido");
                    $batch->update(['status' => 'failed']);
                } else {
                    Log::info("📊 Batch {$batch->id}: {$totalErrors} errores de {$batch->total_images} ({$errorRate}%)");
                }
            }
        }
    }
}
