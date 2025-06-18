<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Jobs\ProcessImageImmediatelyJob;
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
        $errors = [];
        try {
            foreach ($this->mapping as $asignacion) {
                $nombreImagen = basename($asignacion['imagen']);
                $moduloPath = trim($asignacion['modulo']);
                $fullImagePath = $this->tempPath . '/' . $nombreImagen;

                if (!file_exists($fullImagePath)) {
                    $errors[] = "No se encontró la imagen: $nombreImagen";
                    continue;
                }

                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    $errors[] = "No se encontró el módulo para: $moduloPath";
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
                    $image = $folder->storeImage(file_get_contents($fullImagePath), $nombreImagen);
                    dispatch(new ProcessImageImmediatelyJob($image->id, $this->batchId));
                } catch (\Exception $e) {
                    Log::error("Error asignando imagen $nombreImagen al módulo $moduloPath: " . $e->getMessage());
                    $batch->increment('errors');
                    $batch->update([
                        'error_messages' => array_merge($batch->error_messages ?? [], [$e->getMessage()])
                    ]);
                    $errors[] = "Error interno al asignar imagen: $nombreImagen";
                }
            }

            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }
        } catch (\Throwable $e) {
            Log::error("❌ Error en HandleZipMappingJob global: " . $e->getMessage());
        }
    }
}
