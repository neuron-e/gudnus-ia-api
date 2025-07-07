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
use Illuminate\Support\Facades\DB;
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

        // ✅ VERIFICACIÓN CRÍTICA: Si el batch ya está completado, no procesar
        if (in_array($batch->status, ['completed', 'completed_with_errors', 'failed'])) {
            Log::warning("⚠️ Batch {$this->batchId} ya está en estado final: {$batch->status}. Saltando procesamiento.");
            return;
        }

        $nombreImagen = basename($this->asignacion['imagen']);
        $moduloPath = trim($this->asignacion['modulo']);

        Log::debug("📥 Procesando imagen ZIP: {$nombreImagen} → {$moduloPath} [Batch: {$this->batchId}]");

        // ✅ Verificar que no hemos excedido el límite esperado
        $expected = $batch->dispatched_total > 0 ? $batch->dispatched_total : $batch->total;
        $current = $batch->processed + ($batch->errors ?? 0);

        if ($current >= $expected) {
            Log::warning("⚠️ Batch {$this->batchId} ya alcanzó el límite: {$current}/{$expected}. Saltando.");
            return;
        }

        // Buscar archivo extraído
        $extractedFile = null;
        foreach (File::allFiles($this->tempPath) as $file) {
            if (strtolower($file->getFilename()) === strtolower($nombreImagen)) {
                $extractedFile = $file->getPathname();
                break;
            }
        }

        if (!$extractedFile || !file_exists($extractedFile)) {
            Log::error("❌ Archivo no encontrado: {$nombreImagen}");
            $this->incrementError($batch, "Archivo no encontrado: {$nombreImagen}");
            return;
        }

        // Buscar folder por full_path ya que así viene del mapping
        $folder = Folder::where('project_id', $this->projectId)
            ->where('full_path', $moduloPath)
            ->first();

        if (!$folder) {
            Log::error("❌ Módulo no encontrado: {$moduloPath}");
            $this->incrementError($batch, "Módulo no encontrado: {$moduloPath}");
            return;
        }

        try {
            // ✅ Usar transacción para evitar condiciones de carrera
            DB::transaction(function () use ($folder, $extractedFile, $nombreImagen, $batch) {

                // ✅ Verificar NUEVAMENTE el estado del batch dentro de la transacción
                $batch->refresh();
                if (in_array($batch->status, ['completed', 'completed_with_errors', 'failed'])) {
                    Log::warning("⚠️ Batch {$this->batchId} completado durante transacción. Abortando.");
                    return;
                }

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

                // ✅ Crear imagen con estructura real (sin project_id, sin batch_id)
                $image = Image::create([
                    'folder_id' => $folder->id,
                    'original_path' => $wasabiPath,
                    'status' => 'uploaded',
                    'is_counted' => false, // ✅ Usar el campo que ya existe
                ]);

                Log::debug("✅ Imagen creada: {$image->id} para batch {$this->batchId}");

                // Procesar imagen
                $service = app(ImageProcessingService::class);
                $processed = $service->process($image, $this->batchId);

                if ($processed && $processed->status === 'processed') {
                    // ✅ Marcar como contada y incrementar batch
                    $image->update(['is_counted' => true]);
                    $this->incrementProcessed($batch);
                    Log::debug("✅ Imagen procesada exitosamente: {$image->id}");
                } else {
                    $this->incrementError($batch, "Fallo al procesar: {$nombreImagen}");
                    Log::error("❌ Fallo al procesar imagen: {$image->id}");
                }
            });

        } catch (\Throwable $e) {
            Log::error("❌ Error procesando {$nombreImagen}: " . $e->getMessage());
            $this->incrementError($batch, "Error procesando {$nombreImagen}: " . $e->getMessage());
        }
    }

    /**
     * ✅ Incrementar contador de procesadas de forma atómica
     */
    private function incrementProcessed(ImageBatch $batch): void
    {
        DB::transaction(function () use ($batch) {
            // ✅ Solo incrementar si el batch sigue en processing y no hemos excedido el límite
            $expected = $batch->dispatched_total > 0 ? $batch->dispatched_total : $batch->total;

            $affected = ImageBatch::where('id', $batch->id)
                ->where('status', 'processing')
                ->where(DB::raw('processed + COALESCE(errors, 0)'), '<', $expected)
                ->increment('processed');

            if ($affected > 0) {
                Log::debug("✅ Incrementado processed para batch {$batch->id}");
            } else {
                Log::warning("⚠️ No se pudo incrementar processed - batch {$batch->id} posiblemente completo o excedido");
            }
        });
    }

    /**
     * ✅ Incrementar contador de errores de forma atómica
     */
    private function incrementError(ImageBatch $batch, string $message): void
    {
        DB::transaction(function () use ($batch, $message) {
            // ✅ Recargar el batch para obtener valores actuales
            $batch->refresh();

            $errors = $batch->error_messages ?? [];
            $errors[] = $message;

            // ✅ Limitar errores guardados para evitar overflow
            if (count($errors) > 100) {
                $errors = array_slice($errors, -100);
            }

            // ✅ Solo incrementar si el batch sigue en processing
            ImageBatch::where('id', $batch->id)
                ->where('status', 'processing')
                ->update([
                    'errors' => DB::raw('COALESCE(errors, 0) + 1'),
                    'error_messages' => json_encode($errors),
                    'updated_at' => now()
                ]);

            Log::debug("✅ Incrementado error para batch {$batch->id}: {$message}");
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::error("❌ Job ProcessZipImageJob FAILED para batch {$this->batchId}: " . $e->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $this->incrementError($batch, "Job failed: " . $e->getMessage());
        }
    }
}
