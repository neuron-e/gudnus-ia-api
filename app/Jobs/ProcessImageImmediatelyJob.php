<?php

namespace App\Jobs;

use App\Mail\ImagesProcessedMail;
use App\Models\AnalysisBatch;
use App\Models\ImageAnalysisResult;
use App\Models\ProcessedImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class ProcessBulkImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ Timeouts ajustados para análisis IA masivo
    public $timeout = 1800; // 30 minutos para lotes grandes (Azure puede ser lento)
    public $tries = 2; // Solo 2 intentos para análisis IA (es costoso)

    public function __construct(
        public array $imageIds,
        public ?string $notifyEmail = null,
        public ?int $batchId = null
    ) {}

    public function handle()
    {
        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        // ✅ Marcar batch como activo al inicio
        if ($batch) {
            $batch->touch();
            Log::info("🚀 Iniciando análisis IA para batch {$batch->id} con {$batch->total_images} imágenes");
        }

        $images = ProcessedImage::with('image')->whereIn('image_id', $this->imageIds)->get();
        $processedCount = 0;
        $errorCount = 0;
        $errorMessages = [];

        foreach ($images as $processed) {
            try {
                $result = $this->processImage($processed, $batch);
                if ($result) {
                    $processedCount++;
                } else {
                    $errorCount++;
                }

                // ✅ Actualizar progreso cada 10 imágenes o al final
                if (($processedCount + $errorCount) % 10 === 0 || ($processedCount + $errorCount) === count($images)) {
                    if ($batch) {
                        $batch->touch(); // Mantener el batch activo
                        Log::debug("📊 Progreso batch {$batch->id}: {$batch->processed_images} completadas, $errorCount errores");
                    }
                }

                // ✅ Rate limiting para Azure (evitar 429 errors)
                if (count($images) > 50) {
                    usleep(100000); // 100ms de pausa entre requests para lotes grandes
                }

            } catch (\Throwable $e) {
                $errorCount++;
                $errorMessage = "Error procesando imagen {$processed->image_id}: " . $e->getMessage();
                $errorMessages[] = $errorMessage;
                Log::error("❌ $errorMessage");

                // ✅ Marcar imagen individual como fallida
                $processed->image?->update(['is_processed' => false]);
            }
        }

        // ✅ Verificación final y actualización del batch
        if ($batch) {
            $batch->refresh();
            $totalProcessed = $batch->processed_images;

            // Determinar estado final
            if ($totalProcessed >= $batch->total_images) {
                $finalStatus = $errorCount > 0 ? 'completed_with_errors' : 'completed';
                $batch->update(['status' => $finalStatus]);

                Log::info("🎉 Batch {$batch->id} completado: $totalProcessed procesadas, $errorCount errores, estado: $finalStatus");

                // Enviar email de notificación
                if ($this->notifyEmail && $totalProcessed > 0) {
                    try {
                        Mail::to($this->notifyEmail)->send(new ImagesProcessedMail($totalProcessed));
                    } catch (\Throwable $e) {
                        Log::warning("⚠️ No se pudo enviar email de notificación: " . $e->getMessage());
                    }
                }
            } else {
                Log::info("📊 Batch {$batch->id} en progreso: {$totalProcessed}/{$batch->total_images} procesadas");
            }

            // ✅ Guardar errores en el batch si los hay (aunque AnalysisBatch no tiene error_messages)
            if (!empty($errorMessages)) {
                Log::warning("⚠️ Errores en batch {$batch->id}: " . implode('; ', array_slice($errorMessages, 0, 5)));
            }
        }

        Log::info("✅ Job de análisis IA completado: $processedCount exitosas, $errorCount errores");
    }

    /**
     * ✅ Procesar una imagen individual
     */
    private function processImage(ProcessedImage $processed, ?AnalysisBatch $batch): bool
    {
        $image = $processed->image;
        $wasabiDisk = Storage::disk('wasabi');

        // Verificaciones previas
        if (!$processed->corrected_path || !$wasabiDisk->exists($processed->corrected_path)) {
            Log::warning("⚠️ Imagen {$image->id}: no tiene path corregido o no existe en Wasabi");
            return false;
        }

        if ($image->is_processed) {
            Log::debug("ℹ️ Imagen {$image->id} ya está procesada, saltando...");
            return true; // Ya procesada, contar como éxito
        }

        $tempPath = null;
        try {
            // ✅ Descargar con ID único para evitar colisiones
            $tempPath = storage_path('app/tmp/' . uniqid('azure_analysis_', true) . '.jpg');
            $imageContent = $wasabiDisk->get($processed->corrected_path);

            if (!$imageContent) {
                throw new \Exception("No se pudo descargar el contenido de la imagen desde Wasabi");
            }

            file_put_contents($tempPath, $imageContent);

            // Verificar que el archivo se creó correctamente
            if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                throw new \Exception("Archivo temporal está vacío o no se creó");
            }

            // ✅ Llamada a Azure con mejor manejo de errores
            $response = Http::timeout(60) // 60 segundos timeout por imagen
            ->retry(2, 1000) // 2 reintentos con 1 segundo de espera
            ->withHeaders([
                'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                'Content-Type' => 'application/octet-stream',
            ])
                ->withBody(file_get_contents($tempPath), 'application/octet-stream')
                ->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();

                if ($statusCode === 429) {
                    Log::warning("⚠️ Rate limit alcanzado en Azure para imagen {$image->id}, reintentando en 5 segundos...");
                    sleep(5); // Esperar antes de reintentar
                    throw new \Exception("Rate limit de Azure alcanzado");
                }

                throw new \Exception("Azure API error {$statusCode}: $errorBody");
            }

            $json = $response->json();
            if (!$json || !isset($json['predictions'])) {
                throw new \Exception("Respuesta de Azure inválida o sin predictions");
            }

            // ✅ Procesar respuesta y guardar datos
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

            // Guardar análisis
            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill($counts);
            $image->analysisResult()->save($analysis);

            // Guardar respuesta JSON
            $processed->ai_response_json = json_encode($json);
            $processed->save();

            // Marcar como procesada
            $image->update(['is_processed' => true]);

            // ✅ Incrementar contador del batch
            if ($batch) {
                $batch->increment('processed_images');
            }

            Log::debug("✅ Imagen {$image->id} analizada exitosamente");
            return true;

        } catch (\Throwable $e) {
            Log::error("❌ Error analizando imagen {$image->id}: " . $e->getMessage());
            return false;
        } finally {
            // ✅ Cleanup siempre se ejecuta
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * ✅ Manejo de fallos del job completo
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job de análisis IA falló completamente: " . $exception->getMessage());

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);
            if ($batch) {
                $batch->update(['status' => 'failed']);
                Log::error("❌ Batch {$batch->id} marcado como fallido debido a fallo del job");
            }
        }
    }

    /**
     * ✅ Timeout dinámico basado en el número de imágenes
     */
    public function retryUntil()
    {
        $imageCount = count($this->imageIds);

        if ($imageCount > 100) {
            return now()->addHours(3); // 3 horas para lotes muy grandes
        } elseif ($imageCount > 50) {
            return now()->addHours(2); // 2 horas para lotes grandes
        } else {
            return now()->addHour(); // 1 hora para lotes pequeños
        }
    }

    /**
     * ✅ Backoff considerando rate limits de Azure
     */
    public function backoff()
    {
        $imageCount = count($this->imageIds);

        if ($imageCount > 100) {
            return [300, 900]; // 5min, 15min para lotes grandes (Azure rate limits)
        } else {
            return [120, 600]; // 2min, 10min para lotes normales
        }
    }
}
