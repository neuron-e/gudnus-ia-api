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
use App\Jobs\FinalizeBatchJob;

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

        Log::info("🚀 INICIANDO HandleZipMappingJob para batch {$this->batchId} con " . count($this->mapping) . " asignaciones");
        Log::info("📦 ZIP recibido: {$this->zipPath}");

        $batch->update(['status' => 'processing']);
        $tempPath = null;

        try {
            if (!file_exists($this->zipPath)) {
                throw new \Exception("Archivo ZIP no encontrado: {$this->zipPath}");
            }

            $zipSize = filesize($this->zipPath);
            Log::info("✅ ZIP verificado: {$zipSize} bytes");

            $tempPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());
            if (File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            File::makeDirectory($tempPath, 0755, true);
            Log::info("📁 Directorio temporal creado: {$tempPath}");

            Log::info("📦 Extrayendo ZIP...");
            $zip = new \ZipArchive;
            $result = $zip->open($this->zipPath);
            if ($result !== true) {
                throw new \Exception("No se pudo abrir el ZIP (código: {$result})");
            }
            $zip->extractTo($tempPath);
            $numFiles = $zip->numFiles;
            $zip->close();

            Log::info("✅ ZIP extraído: {$numFiles} archivos en {$tempPath}");

            $batch->update(['expected_total' => count($this->mapping)]);

            // ✅ Procesamiento en chunks para distribuir la carga
            $chunks = array_chunk($this->mapping, 100);
            foreach ($chunks as $chunkIndex => $chunk) {
                foreach ($chunk as $index => $asignacion) {
                    dispatch(new ProcessZipImageJob(
                        $this->projectId,
                        $asignacion,
                        $tempPath,
                        $this->batchId
                    ))->onQueue('images');
                }
            }
            $batch->update(['dispatched_total' => count($this->mapping)]);
            // ✅ Programar job de finalización después de todos los sub-jobs
            dispatch(new FinalizeBatchJob($this->batchId))->delay(now()->addMinutes(10));

        } catch (\Throwable $e) {
            Log::error("❌ ERROR CRÍTICO en HandleZipMappingJob: " . $e->getMessage());
            $batch->update([
                'status' => 'failed',
                'error_messages' => ["Error crítico: " . $e->getMessage()]
            ]);
        } finally {
/*            if ($tempPath && File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
                Log::info("🧹 Directorio temporal eliminado: {$tempPath}");
            }*/

            if (file_exists($this->zipPath)) {
                @unlink($this->zipPath);
                Log::info("🧹 ZIP eliminado: {$this->zipPath}");
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job HandleZipMappingJob FAILED para batch {$this->batchId}: " . $exception->getMessage());
        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
