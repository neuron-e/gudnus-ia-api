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
        // ✅ AGREGAR LOG INICIAL
        Log::info("🚀 ProcessZipImageJob iniciado", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'imagen' => $this->asignacion['imagen'] ?? 'N/A',
            'modulo' => $this->asignacion['modulo'] ?? 'N/A',
            'temp_path' => $this->tempPath
        ]);

        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch no encontrado: {$this->batchId}");
            return;
        }

        $nombreImagen = basename($this->asignacion['imagen']);
        $extension = pathinfo($nombreImagen, PATHINFO_EXTENSION);
        $nameWithoutExt = pathinfo($nombreImagen, PATHINFO_FILENAME);
        $moduloPath = trim($this->asignacion['modulo']);

        Log::debug("📋 Procesando imagen", [
            'nombre_imagen' => $nombreImagen,
            'modulo_path' => $moduloPath
        ]);

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

        Log::debug("✅ Archivo encontrado: {$extractedFile}");

        // Buscar folder
        $folder = Folder::where('project_id', $this->projectId)
            ->where('full_path', $moduloPath)
            ->first();

        if (!$folder) {
            $this->incrementError($batch, "Módulo no encontrado: {$moduloPath}");
            return;
        }

        Log::debug("✅ Folder encontrado", [
            'folder_id' => $folder->id,
            'folder_name' => $folder->name
        ]);

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

            // ✅ Subir nueva imagen
            $imageContent = file_get_contents($extractedFile);
            $wasabiPath = "projects/{$this->projectId}/images/{$nombreImagen}";

            Log::debug("📤 Subiendo imagen a Wasabi", [
                'wasabi_path' => $wasabiPath,
                'image_size' => strlen($imageContent)
            ]);

            Storage::disk('wasabi')->put($wasabiPath, $imageContent);

            // Crear imagen
            $image = Image::create([
                'folder_id' => $folder->id,
                'original_path' => $wasabiPath,
                'status' => 'uploaded',
                'is_counted' => false,
            ]);

            Log::info("✅ Imagen creada en BD", [
                'image_id' => $image->id,
                'folder_id' => $folder->id,
                'wasabi_path' => $wasabiPath
            ]);

            // ✅ Procesar imagen
            $service = app(ImageProcessingService::class);
            $processed = $service->process($image, $this->batchId);

            Log::debug("🔄 Resultado del procesamiento", [
                'image_id' => $image->id,
                'processed_not_null' => $processed !== null,
                'image_status' => $processed ? $processed->status : 'null',
                'has_processed_image' => $processed ? ($processed->processedImage !== null) : false
            ]);

            // ✅ LÓGICA MEJORADA: Verificar múltiples condiciones de éxito
            $wasSuccessful = false;

            if ($processed) {
                // ✅ Verificar si tiene imagen procesada (independientemente del status)
                if ($processed->processedImage && $processed->processedImage->corrected_path) {
                    $wasSuccessful = true;
                    Log::debug("✅ Éxito: Imagen tiene corrected_path");
                }
                // ✅ O si el status es processed
                elseif ($processed->status === 'processed') {
                    $wasSuccessful = true;
                    Log::debug("✅ Éxito: Status es 'processed'");
                }
                // ✅ O si no es error (fallback exitoso)
                elseif ($processed->status !== 'error') {
                    $wasSuccessful = true;
                    Log::debug("✅ Éxito: Status no es error ({$processed->status})");
                }
            }

            if ($wasSuccessful) {
                // ✅ Marcar como contada e incrementar
                $image->update(['is_counted' => true]);

                // ✅ INCREMENTAR BATCH de forma thread-safe
                \DB::transaction(function() use ($batch) {
                    $batch->increment('processed');
                    $batch->touch(); // Actualizar timestamp
                });

                Log::info("✅ Imagen procesada exitosamente", [
                    'image_id' => $image->id,
                    'batch_processed' => $batch->fresh()->processed,
                    'status' => $processed->status,
                    'processing_method' => $processed->analysisResult?->processing_method ?? 'unknown'
                ]);
            } else {
                $errorMsg = $processed
                    ? "Procesamiento falló con status: {$processed->status}"
                    : "Service retornó null";

                $this->incrementError($batch, "Error procesando {$nombreImagen}: {$errorMsg}");

                Log::warning("⚠️ Imagen no se pudo procesar correctamente", [
                    'image_id' => $image->id,
                    'error' => $errorMsg
                ]);
            }

        } catch (\Throwable $e) {
            Log::error("❌ Error procesando imagen {$nombreImagen}: " . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
            $this->incrementError($batch, "Error procesando {$nombreImagen}: " . $e->getMessage());
        }
    }

    private function incrementError(ImageBatch $batch, string $message): void
    {
        Log::error("❌ Error en ProcessZipImageJob: {$message}");

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
        Log::error("❌ ProcessZipImageJob FAILED", [
            'batch_id' => $this->batchId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $this->incrementError($batch, "Job failed: " . $e->getMessage());
        }
    }
}
