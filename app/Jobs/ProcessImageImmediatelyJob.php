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

class ProcessImageImmediatelyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 180; // ✅ 3 minutos por imagen
    public $tries = 4; // ✅ Más reintentos
    public $maxExceptions = 3;

    // ✅ Backoff exponencial con jitter
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

                // ✅ Actualizar batch si existe
                if ($batch) {
                    $batch->increment('processed_images');
                    $batch->touch();

                    // ✅ Log de progreso cada 10 imágenes
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

    private function handleProcessingError(?AnalysisBatch $batch, string $error): void
    {
        if ($batch) {
            // ✅ No incrementar processed_images en caso de error
            $batch->touch(); // Solo actualizar timestamp
        }

        // ✅ Si es el último intento, loguear como error crítico
        if ($this->attempts() >= $this->tries) {
            Log::critical("💀 Imagen {$this->imageId} falló definitivamente después de {$this->attempts()} intentos: {$error}");
        }
    }

    /**
     * ✅ Procesar una imagen con análisis IA de Azure (con manejo mejorado de errores)
     */
    private function processImageWithAI(Image $image): bool
    {
        // ✅ Verificar que tiene imagen procesada (recortada)
        if (!$image->processedImage || !$image->processedImage->corrected_path) {
            Log::warning("⚠️ Imagen {$image->id}: no ha sido recortada, no se puede analizar con IA");
            return false;
        }

        $correctedPath = $image->processedImage->corrected_path;

        if (!Storage::disk('wasabi')->exists($correctedPath)) {
            Log::warning("⚠️ Imagen {$image->id}: archivo recortado no existe en Wasabi: {$correctedPath}");
            return false;
        }

        // ✅ Si ya está procesada con IA, saltarla
        if ($image->is_processed) {
            Log::debug("ℹ️ Imagen {$image->id} ya está procesada con IA");
            return true;
        }

        try {
            // ✅ Obtener contenido de la imagen
            $imageContent = Storage::disk('wasabi')->get($correctedPath);

            Log::debug("🤖 Enviando imagen {$image->id} a Azure para análisis IA", [
                'file_path' => $correctedPath,
                'size_bytes' => strlen($imageContent),
                'attempt' => $this->attempts()
            ]);

            // ✅ Llamada a Azure con mejor manejo de errores y reintentos
            $response = Http::timeout(90) // ✅ Timeout más generoso
            ->retry(3, function ($exception, $request) {
                // ✅ Retry en timeouts y errores 5xx
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    Log::warning("🔄 Reintentando por error de conexión: " . $exception->getMessage());
                    sleep(2); // Pausa antes de reintento
                    return true;
                }

                if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                    $status = $exception->response?->status() ?? 0;
                    if ($status >= 500 || $status === 429) {
                        $delay = $status === 429 ? 10 : 5; // Más delay para rate limiting
                        Log::warning("🔄 Reintentando por status {$status}, esperando {$delay}s");
                        sleep($delay);
                        return true;
                    }
                }

                return false;
            }, 1000) // 1 segundo entre reintentos del Http::retry
            ->withHeaders([
                'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                'Content-Type' => 'application/octet-stream',
            ])
                ->withBody($imageContent, 'application/octet-stream')
                ->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

            if (!$response->successful()) {
                $statusCode = $response->status();
                $responseBody = $response->body();

                Log::error("❌ Azure prediction failed para imagen {$image->id}", [
                    'status' => $statusCode,
                    'body' => $responseBody,
                    'attempt' => $this->attempts()
                ]);

                // ✅ Rate limiting específico
                if ($statusCode === 429) {
                    $retryAfter = $response->header('Retry-After', 60);
                    Log::warning("⚠️ Rate limit alcanzado para imagen {$image->id}, retry after: {$retryAfter}s");

                    // ✅ Re-encolar el job con delay
                    $this->release(now()->addSeconds($retryAfter + rand(5, 15)));
                    return false;
                }

                // ✅ Errores temporales de Azure
                if ($statusCode >= 500) {
                    throw new \Exception("Azure server error {$statusCode}: {$responseBody}");
                }

                // ✅ Errores de cliente (4xx) - no reintentar
                Log::error("💀 Error cliente Azure {$statusCode} para imagen {$image->id}, no reintentando");
                return false;
            }

            $json = $response->json();
            if (!$json || !isset($json['predictions'])) {
                throw new \Exception("Respuesta de Azure inválida o vacía");
            }

            Log::debug("✅ Azure prediction response para imagen {$image->id}", [
                'predictions_count' => count($json['predictions'])
            ]);

            // ✅ Procesar respuesta (mismo mapeo que en controller)
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

            // ✅ Guardar resultados
            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill($counts);
            $image->analysisResult()->save($analysis);

            // ✅ Guardar respuesta JSON
            $image->processedImage->ai_response_json = json_encode($json);
            $image->processedImage->save();

            // ✅ Marcar como procesada con IA
            $image->update(['is_processed' => true]);

            Log::debug("✅ Análisis IA guardado para imagen {$image->id}", $counts);
            return true;

        } catch (\Throwable $e) {
            Log::error("❌ Error en análisis IA para imagen {$image->id}: " . $e->getMessage(), [
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            // ✅ Re-throw para que el framework maneje el retry
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessImageImmediatelyJob falló definitivamente para imagen {$this->imageId}: " . $exception->getMessage());

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);
            if ($batch) {
                $batch->touch(); // Actualizar timestamp para indicar actividad
            }
        }
    }
}
