<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use App\Models\ImageAnalysisResult;
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

    public $timeout = 3600;
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
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado");
            return;
        }

        Log::info("🚀 Iniciando HandleZipMappingJob para batch {$this->batchId} con " . count($this->mapping) . " asignaciones");

        $batch->update(['status' => 'processing']);
        $processedCount = 0;
        $errorCount = 0;
        $errorMessages = [];

        try {
            foreach ($this->mapping as $index => $asignacion) {
                $nombreImagen = basename($asignacion['imagen']);
                $moduloPath = trim($asignacion['modulo']);
                $fullImagePath = $this->tempPath . '/' . $nombreImagen;

                Log::debug("📝 Procesando [{$index}]: {$nombreImagen} -> {$moduloPath}");

                // ✅ Verificaciones básicas
                if (!file_exists($fullImagePath) || !is_file($fullImagePath)) {
                    $errorMessages[] = "Archivo no encontrado o inválido: $nombreImagen";
                    $errorCount++;
                    $this->updateBatchError($batch);
                    continue;
                }

                // ✅ Verificar extensión
                $extension = strtolower(pathinfo($fullImagePath, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'])) {
                    $errorMessages[] = "Extensión no válida: $nombreImagen";
                    $errorCount++;
                    $this->updateBatchError($batch);
                    continue;
                }

                // ✅ Buscar carpeta
                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    $errorMessages[] = "Módulo no encontrado: $moduloPath";
                    $errorCount++;
                    $this->updateBatchError($batch);
                    continue;
                }

                try {
                    // ✅ Limpiar imágenes existentes
                    $this->cleanExistingImages($folder);

                    // ✅ Crear y subir nueva imagen
                    $imageContent = file_get_contents($fullImagePath);
                    if ($imageContent === false) {
                        throw new \Exception("No se pudo leer el archivo");
                    }

                    $wasabiPath = "projects/{$this->projectId}/images/" . uniqid('zip_') . '_' . $nombreImagen;
                    Storage::disk('wasabi')->put($wasabiPath, $imageContent);

                    $image = Image::create([
                        'folder_id' => $folder->id,
                        'project_id' => $this->projectId,
                        'filename' => $nombreImagen,
                        'original_path' => $wasabiPath,
                        'status' => 'uploaded'
                    ]);

                    Log::info("📁 Imagen creada: ID {$image->id}, archivo: {$nombreImagen}");

                    // ✅ Procesar con ImageProcessingService
                    $success = $this->processImageWithService($image);

                    if ($success) {
                        $processedCount++;
                        $this->updateBatchSuccess($batch);
                        Log::info("✅ [{$processedCount}] Imagen procesada: {$nombreImagen}");
                    } else {
                        $errorCount++;
                        $errorMessages[] = "Error procesando: $nombreImagen";
                        $this->updateBatchError($batch);
                        Log::error("❌ Error procesando imagen: {$nombreImagen}");
                    }

                    // ✅ Log de progreso cada 25 imágenes
                    if (($processedCount + $errorCount) % 25 === 0) {
                        $batch->refresh();
                        Log::info("📊 Progreso: {$batch->processed}/{$batch->total} procesadas, {$batch->errors} errores");
                    }

                } catch (\Exception $e) {
                    $errorCount++;
                    $errorMessages[] = "Error con imagen $nombreImagen: " . $e->getMessage();
                    $this->updateBatchError($batch);
                    Log::error("❌ Exception procesando {$nombreImagen}: " . $e->getMessage());
                }
            }

            // ✅ Finalización
            $this->finalizeBatch($batch, $errorMessages);

        } catch (\Throwable $e) {
            Log::error("❌ Error crítico en HandleZipMappingJob: " . $e->getMessage());
            $batch->update([
                'status' => 'failed',
                'error_messages' => array_merge($errorMessages, ["Error crítico: " . $e->getMessage()])
            ]);
        } finally {
            // ✅ Cleanup
            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
                Log::info("🧹 Directorio temporal eliminado: {$this->tempPath}");
            }
        }
    }

    private function cleanExistingImages(Folder $folder): void
    {
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
    }

    private function processImageWithService(Image $image): bool
    {
        try {
            $imageProcessingService = app(\App\Services\ImageProcessingService::class);
            $result = $imageProcessingService->process($image);

            return $result && $result->status !== 'error';
        } catch (\Exception $e) {
            Log::error("Error en ImageProcessingService para imagen {$image->id}: " . $e->getMessage());
            return false;
        }
    }

    private function updateBatchSuccess(ImageBatch $batch): void
    {
        $batch->increment('processed');
        $batch->touch();
    }

    private function updateBatchError(ImageBatch $batch): void
    {
        $batch->increment('errors');
        $batch->touch();
    }

    private function finalizeBatch(ImageBatch $batch, array $errorMessages): void
    {
        $batch->refresh();

        $totalProcessed = $batch->processed;
        $totalErrors = $batch->errors ?? 0;

        Log::info("🔍 Estado final del batch {$batch->id}:", [
            'processed' => $totalProcessed,
            'errors' => $totalErrors,
            'total' => $batch->total
        ]);

        if ($totalErrors === 0 && $totalProcessed > 0) {
            $finalStatus = 'completed';
        } elseif ($totalProcessed > 0) {
            $finalStatus = 'completed_with_errors';
        } else {
            $finalStatus = 'failed';
        }

        $batch->update([
            'status' => $finalStatus,
            'error_messages' => $errorMessages
        ]);

        Log::info("🎉 Batch {$batch->id} finalizado: {$totalProcessed} procesadas, {$totalErrors} errores, estado: {$finalStatus}");
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
