<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Services\ImageProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class HandleZipMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hora para lotes grandes
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $mapping,
        public string $tempPath,
        public int $batchId
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) return;

        $asignadas = 0;
        $errores = 0;
        $errorMessages = [];

        try {
            $batch->update(['status' => 'processing']);
            Log::info("🚀 Iniciando HandleZipMappingJob para batch {$this->batchId} con " . count($this->mapping) . " asignaciones");

            foreach ($this->mapping as $index => $asignacion) {
                $nombreImagen = basename($asignacion['imagen']);
                $moduloPath = trim($asignacion['modulo']);
                $fullImagePath = $this->tempPath . '/' . $nombreImagen;

                // ✅ Logging detallado para debug
                Log::debug("Procesando asignación {$index}: {$nombreImagen} -> {$moduloPath}");

                // Verificaciones de archivo
                if (!file_exists($fullImagePath)) {
                    $errorMessages[] = "No se encontró la imagen: $nombreImagen";
                    $errores++;
                    continue;
                }

                if (!is_file($fullImagePath)) {
                    $errorMessages[] = "El elemento '$nombreImagen' no es un archivo válido";
                    $errores++;
                    continue;
                }

                // Verificar extensión
                $extension = strtolower(pathinfo($fullImagePath, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'];
                if (!in_array($extension, $allowedExtensions)) {
                    $errorMessages[] = "El archivo '$nombreImagen' no tiene una extensión de imagen válida";
                    $errores++;
                    continue;
                }

                // Buscar carpeta
                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    $errorMessages[] = "No se encontró el módulo para: $moduloPath";
                    $errores++;
                    continue;
                }

                // Eliminar imágenes existentes
                foreach ($folder->images as $existing) {
                    if (Storage::disk('wasabi')->exists($existing->original_path)) {
                        Storage::disk('wasabi')->delete($existing->original_path);
                    }
                    if ($existing->processedImage && Storage::disk('wasabi')->exists($existing->processedImage->corrected_path)) {
                        Storage::disk('wasabi')->delete($existing->processedImage->corrected_path);
                    }

                    $existing->processedImage()?->delete();
                    $existing->analysisResult()?->delete();
                    $existing->delete();
                }

                try {
                    if (!is_readable($fullImagePath)) {
                        throw new \Exception("No se puede leer el archivo: $nombreImagen");
                    }

                    $imageContent = file_get_contents($fullImagePath);
                    if ($imageContent === false) {
                        throw new \Exception("Error al leer el contenido del archivo: $nombreImagen");
                    }

                    // ✅ Crear imagen
                    $image = $folder->storeImage($imageContent, $nombreImagen);

                    // ✅ CORREGIDO: Procesar imagen directamente en lugar de despachar job incorrecto
                    $imageProcessingService = app(ImageProcessingService::class);
                    $processedImage = $imageProcessingService->process($image, $this->batchId);

                    if ($processedImage && $processedImage->status !== 'error') {
                        $asignadas++;
                        Log::debug("✅ Imagen {$nombreImagen} procesada correctamente");
                    } else {
                        $errores++;
                        $errorMessages[] = "Error procesando imagen: $nombreImagen";
                        Log::warning("⚠️ Error procesando imagen {$nombreImagen}");
                    }

                    // ✅ Actualizar progreso cada 10 imágenes
                    if (($asignadas + $errores) % 10 === 0) {
                        $batch->refresh();
                        Log::info("📊 Progreso batch {$this->batchId}: {$batch->processed} procesadas, {$batch->errors} errores");
                    }

                } catch (\Exception $e) {
                    Log::error("Error asignando imagen $nombreImagen: " . $e->getMessage());
                    $errores++;
                    $errorMessages[] = "Error interno al asignar imagen: $nombreImagen - " . $e->getMessage();

                    // Actualizar errores en el batch
                    $batch->increment('errors');
                    $batch->touch();
                }
            }

            // ✅ Actualización final del batch
            $batch->refresh();
            $totalProcesadas = $batch->processed; // Usar el valor real del batch
            $totalErrores = $batch->errors ?? 0;

            if ($totalErrores === 0) {
                $finalStatus = 'completed';
            } elseif ($totalProcesadas > 0) {
                $finalStatus = 'completed_with_errors';
            } else {
                $finalStatus = 'failed';
            }

            $batch->update([
                'status' => $finalStatus,
                'error_messages' => $errorMessages
            ]);

            // Cleanup
            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }

            Log::info("🎉 HandleZipMappingJob completado. Batch {$this->batchId}: {$totalProcesadas} procesadas, {$totalErrores} errores, estado: {$finalStatus}");

        } catch (\Throwable $e) {
            Log::error("❌ Error crítico en HandleZipMappingJob batch {$this->batchId}: " . $e->getMessage());

            $batch->update([
                'status' => 'failed',
                'error_messages' => array_merge($errorMessages ?? [], ["Error crítico: " . $e->getMessage()])
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ HandleZipMappingJob falló para batch {$this->batchId}: " . $exception->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
