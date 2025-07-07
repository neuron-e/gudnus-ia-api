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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessZipImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $asignacion,
        public string $tempPath,
        public int $batchId
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch no encontrado: {$this->batchId}");
            return;
        }

        $nombreImagen = basename($this->asignacion['imagen']);
        $moduloPath = trim($this->asignacion['modulo']);

        // Buscar archivo extraído
        $extractedFile = null;
        foreach (File::allFiles($this->tempPath) as $file) {
            if (strtolower($file->getFilename()) === strtolower($nombreImagen)) {
                $extractedFile = $file->getPathname();
                break;
            }
        }

        if (!$extractedFile || !file_exists($extractedFile)) {
            $this->incrementError($batch, "Archivo no encontrado: {$nombreImagen}");
            return;
        }

        // Buscar folder
        $folder = Folder::where('project_id', $this->projectId)
            ->where('full_path', $moduloPath)
            ->first();

        if (!$folder) {
            $this->incrementError($batch, "Módulo no encontrado: {$moduloPath}");
            return;
        }

        try {
            // Eliminar imágenes existentes en el folder
            foreach ($folder->images as $existing) {
                if (Storage::disk('wasabi')->exists($existing->original_path)) {
                    Storage::disk('wasabi')->delete($existing->original_path);
                }
                $existing->processedImage()?->delete();
                $existing->analysisResult()?->delete();
                $existing->delete();
            }

            // Subir nueva imagen
            $imageContent = file_get_contents($extractedFile);
            $wasabiPath = "projects/{$this->projectId}/images/" . uniqid('zip_') . '_' . $nombreImagen;
            Storage::disk('wasabi')->put($wasabiPath, $imageContent);

            // Crear imagen
            $image = Image::create([
                'folder_id' => $folder->id,
                'original_path' => $wasabiPath,
                'status' => 'uploaded',
                'is_counted' => false,
            ]);

            // Procesar imagen
            $service = app(ImageProcessingService::class);
            $processed = $service->process($image, $this->batchId);

            if ($processed && $processed->status === 'processed') {
                // ✅ Marcar como contada e incrementar
                $image->update(['is_counted' => true]);
                $batch->increment('processed');
                $batch->touch();
            } else {
                $this->incrementError($batch, "Fallo al procesar: {$nombreImagen}");
            }

        } catch (\Throwable $e) {
            $this->incrementError($batch, "Error procesando {$nombreImagen}: " . $e->getMessage());
        }
    }

    private function incrementError(ImageBatch $batch, string $message): void
    {
        $batch->increment('errors');

        $errors = $batch->error_messages ?? [];
        $errors[] = $message;

        $batch->update([
            'error_messages' => array_slice($errors, -50), // Solo últimos 50 errores
            'updated_at' => now()
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $this->incrementError($batch, "Job failed: " . $e->getMessage());
        }
    }
}
