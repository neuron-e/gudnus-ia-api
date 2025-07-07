<?php

namespace App\Jobs;

use App\Models\ImageBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessZipImageJob;

class HandleZipMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $mapping,
        public string $zipPath,
        public int $batchId
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado");
            return;
        }

        Log::info("🚀 Iniciando procesamiento ZIP para batch {$this->batchId} con " . count($this->mapping) . " imágenes");

        // ✅ Marcar como procesando y establecer total
        $batch->update([
            'status' => 'processing',
            'total' => count($this->mapping),
            'processed' => 0,
            'errors' => 0
        ]);

        try {
            // Extraer ZIP
            $tempPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());
            if (File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            File::makeDirectory($tempPath, 0755, true);

            $zip = new \ZipArchive;
            if ($zip->open($this->zipPath) !== true) {
                throw new \Exception("No se pudo abrir el ZIP");
            }
            $zip->extractTo($tempPath);
            $zip->close();

            Log::info("✅ ZIP extraído en: {$tempPath}");

            // ✅ Despachar TODOS los jobs
            foreach ($this->mapping as $asignacion) {
                dispatch(new ProcessZipImageJob(
                    $this->projectId,
                    $asignacion,
                    $tempPath,
                    $this->batchId
                ))->onQueue('images');
            }

            Log::info("✅ Despachados " . count($this->mapping) . " jobs para batch {$this->batchId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error en HandleZipMappingJob: " . $e->getMessage());
            $batch->update(['status' => 'failed']);
        } finally {
            // Limpiar ZIP original
            if (file_exists($this->zipPath)) {
                @unlink($this->zipPath);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ HandleZipMappingJob FAILED para batch {$this->batchId}: " . $exception->getMessage());
        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
