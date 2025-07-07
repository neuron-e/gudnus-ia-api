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

    public function __construct(
        public int $projectId,
        public array $asignacion,
        public string $tempPath,
        public int $batchId
    ) {}

    public function handle()
    {
        $nombreImagen = basename($this->asignacion['imagen']);
        $moduloPath = trim($this->asignacion['modulo']);
        $batch = ImageBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ Batch no encontrado: {$this->batchId}");
            return;
        }

        Log::debug("📥 Procesando imagen ZIP: {$nombreImagen} → {$moduloPath}");

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

        $folder = Folder::where('project_id', $this->projectId)
            ->where('full_path', $moduloPath)
            ->first();

        if (!$folder) {
            Log::error("❌ Módulo no encontrado: {$moduloPath}");
            $this->incrementError($batch, "Módulo no encontrado: {$moduloPath}");
            return;
        }

        try {
            foreach ($folder->images as $existing) {
                Storage::disk('wasabi')->delete($existing->original_path);
                $existing->processedImage()?->delete();
                $existing->analysisResult()?->delete();
                $existing->delete();
            }

            $imageContent = file_get_contents($extractedFile);
            $wasabiPath = "projects/{$this->projectId}/images/" . uniqid('zip_') . '_' . $nombreImagen;
            Storage::disk('wasabi')->put($wasabiPath, $imageContent);

            $image = Image::create([
                'folder_id' => $folder->id,
                'project_id' => $this->projectId,
                'filename' => $nombreImagen,
                'original_path' => $wasabiPath,
                'status' => 'uploaded'
            ]);

            $service = app(ImageProcessingService::class);
            $processed = $service->process($image, $this->batchId);

            if ($processed && $processed->status === 'processed') {
                $batch->increment('processed');
            } else {
                $this->incrementError($batch, "Fallo al procesar: {$nombreImagen}");
            }

        } catch (\Throwable $e) {
            Log::error("❌ Error procesando {$nombreImagen}: " . $e->getMessage());
            $this->incrementError($batch, $e->getMessage());
        }

        $this->finalizeIfFinished($batch);
    }

    private function incrementError(ImageBatch $batch, string $message): void
    {
        $batch->increment('errors');
        $errors = $batch->error_messages ?? [];
        $errors[] = $message;
        $batch->error_messages = array_slice($errors, -50); // evitar overflow
        $batch->touch();
        $batch->save();
    }

    private function finalizeIfFinished(ImageBatch $batch): void
    {
        $batch->refresh();
        $expected = $batch->expected_total ?? $batch->total;

        if (($batch->processed + $batch->errors) >= $expected) {
            $status = match (true) {
                $batch->processed > 0 && $batch->errors === 0 => 'completed',
                $batch->processed > 0 && $batch->errors > 0 => 'completed_with_errors',
                default => 'failed'
            };

            $batch->status = $status;
            $batch->save();

            Log::info("🎉 Finalizado batch {$batch->id}: {$batch->processed} procesadas, {$batch->errors} errores. Estado: {$status}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("❌ Job ProcessZipImageJob FAILED: " . $e->getMessage());
    }
}
