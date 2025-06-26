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

                // ✅ Verificar que existe Y que es un archivo (no directorio)
                if (!file_exists($fullImagePath)) {
                    $errors[] = "No se encontró la imagen: $nombreImagen";
                    continue;
                }

                // ✅ Nueva verificación: asegurar que es un archivo, no un directorio
                if (!is_file($fullImagePath)) {
                    $errors[] = "El elemento '$nombreImagen' no es un archivo válido (podría ser un directorio)";
                    continue;
                }

                // ✅ Verificar que es una imagen válida por extensión
                $extension = strtolower(pathinfo($fullImagePath, PATHINFO_EXTENSION));
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'];
                if (!in_array($extension, $allowedExtensions)) {
                    $errors[] = "El archivo '$nombreImagen' no tiene una extensión de imagen válida";
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
                    // ✅ Verificar una vez más antes de leer
                    if (!is_readable($fullImagePath)) {
                        throw new \Exception("No se puede leer el archivo: $nombreImagen");
                    }

                    $imageContent = file_get_contents($fullImagePath);

                    // ✅ Verificar que se leyó contenido
                    if ($imageContent === false) {
                        throw new \Exception("Error al leer el contenido del archivo: $nombreImagen");
                    }

                    $image = $folder->storeImage($imageContent, $nombreImagen);
                    dispatch(new ProcessImageImmediatelyJob($image->id, $this->batchId));
                    $asignadas++;

                } catch (\Exception $e) {
                    Log::error("Error asignando imagen $nombreImagen al módulo $moduloPath: " . $e->getMessage());
                    $batch->increment('errors');
                    $batch->update([
                        'error_messages' => array_merge($batch->error_messages ?? [], [$e->getMessage()])
                    ]);
                    $errors[] = "Error interno al asignar imagen: $nombreImagen - " . $e->getMessage();
                }
            }

            // ✅ Actualizar estadísticas del batch
            $batch->update([
                'processed' => $asignadas,
                'status' => count($errors) > 0 ? 'completed_with_errors' : 'completed'
            ]);

            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }

            Log::info("Procesamiento de ZIP completado. Asignadas: $asignadas, Errores: " . count($errors));

        } catch (\Throwable $e) {
            Log::error("❌ Error en HandleZipMappingJob global: " . $e->getMessage());
            $batch->update(['status' => 'failed']);
        }
    }
}
