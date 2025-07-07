<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use App\Services\ImageProcessingService;

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
                $fullImagePath = $this->tempPath . '/' . $asignacion['imagen'];

                Log::debug("📝 Procesando [{$index}]: {$nombreImagen} -> {$moduloPath}");

                if (!file_exists($fullImagePath) || !is_file($fullImagePath)) {
                    // Buscar por nombre suelto
                    $found = collect(File::allFiles($this->tempPath))->first(fn($file) => strtolower($file->getFilename()) === strtolower($nombreImagen));
                    $fullImagePath = $found?->getPathname();

                    if (!$fullImagePath || !file_exists($fullImagePath)) {
                        $errorMessages[] = "Archivo no encontrado: $nombreImagen";
                        $this->updateBatchError($batch);
                        $errorCount++;
                        continue;
                    }
                }

                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    $errorMessages[] = "Módulo no encontrado: $moduloPath";
                    $this->updateBatchError($batch);
                    $errorCount++;
                    continue;
                }

                try {
                    $this->cleanExistingImages($folder);

                    $imageContent = file_get_contents($fullImagePath);
                    if ($imageContent === false) {
                        throw new \Exception("No se pudo leer el archivo");
                    }

                    $wasabiPath = "projects/{$this->projectId}/images/" . uniqid('zip_') . "_" . $nombreImagen;
                    Storage::disk('wasabi')->put($wasabiPath, $imageContent);

                    $image = Image::create([
                        'folder_id' => $folder->id,
                        'project_id' => $this->projectId,
                        'filename' => $nombreImagen,
                        'original_path' => $wasabiPath,
                        'status' => 'uploaded'
                    ]);

                    Log::info("📷 Imagen creada: ID {$image->id}");

                    $service = app(ImageProcessingService::class);
                    $processed = $service->process($image, $this->batchId);

                    if ($processed && $processed->status === 'processed') {
                        $processedCount++;
                        $this->updateBatchSuccess($batch);
                        Log::info("✅ Imagen procesada correctamente: {$nombreImagen}");
                    } else {
                        $errorMessages[] = "Error procesando: {$nombreImagen}";
                        $this->updateBatchError($batch);
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $errorMessages[] = "Error procesando {$nombreImagen}: {$e->getMessage()}";
                    $this->updateBatchError($batch);
                    $errorCount++;
                }
            }

            $this->finalizeBatch($batch, $errorMessages);

        } catch (\Throwable $e) {
            Log::error("❌ Error crítico en HandleZipMappingJob: " . $e->getMessage());
            $batch->update([
                'status' => 'failed',
                'error_messages' => array_merge($errorMessages, ["Error crítico: " . $e->getMessage()])
            ]);
        } finally {
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

        $processed = $batch->processed;
        $errores = $batch->errors ?? 0;

        $status = $processed > 0 && $errores === 0 ? 'completed'
            : ($processed > 0 ? 'completed_with_errors' : 'failed');

        $batch->update([
            'status' => $status,
            'error_messages' => $errorMessages
        ]);

        Log::info("🎉 Batch {$batch->id} finalizado con estado: {$status}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job falló para batch {$this->batchId}: " . $exception->getMessage());
        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
