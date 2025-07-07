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

        // ✅ Verificar que el batch no esté ya completado
        if (in_array($batch->status, ['completed', 'completed_with_errors', 'failed'])) {
            Log::warning("⚠️ Batch {$this->batchId} ya está en estado final: {$batch->status}");
            return;
        }

        Log::info("🚀 INICIANDO HandleZipMappingJob para batch {$this->batchId} con " . count($this->mapping) . " asignaciones");

        $batch->update([
            'status' => 'processing',
            'total' => count($this->mapping),
            'expected_total' => count($this->mapping),
            'dispatched_total' => 0 // ✅ Inicializar en 0
        ]);

        $tempPath = null;

        try {
            if (!file_exists($this->zipPath)) {
                throw new \Exception("Archivo ZIP no encontrado: {$this->zipPath}");
            }

            $tempPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());
            if (File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
            }
            File::makeDirectory($tempPath, 0755, true);

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

            // ✅ Guardar el tempPath en el batch para limpieza posterior
            $batch->update(['temp_path' => $tempPath]);

            // ✅ Despachar jobs en chunks para evitar sobrecargas
            $chunks = array_chunk($this->mapping, 50); // Reducido a 50 por chunk
            $totalDispatched = 0;

            foreach ($chunks as $chunkIndex => $chunk) {
                foreach ($chunk as $asignacion) {
                    // ✅ Verificar nuevamente el estado del batch antes de cada dispatch
                    $batch->refresh();
                    if (in_array($batch->status, ['completed', 'completed_with_errors', 'failed'])) {
                        Log::warning("⚠️ Batch {$this->batchId} completado durante el dispatch. Deteniendo.");
                        break 2; // Salir de ambos loops
                    }

                    dispatch(new ProcessZipImageJob(
                        $this->projectId,
                        $asignacion,
                        $tempPath,
                        $this->batchId
                    ))->onQueue('images');

                    $totalDispatched++;
                }

                // ✅ Actualizar dispatched_total por chunks
                $batch->update(['dispatched_total' => $totalDispatched]);

                // ✅ Pequeña pausa entre chunks para evitar overwhelming
                if ($chunkIndex < count($chunks) - 1) {
                    usleep(100000); // 100ms
                }
            }

            Log::info("✅ Despachados {$totalDispatched} jobs para batch {$this->batchId}");

            // ✅ Programar job de finalización con delay más conservador
            dispatch(new FinalizeBatchJob($this->batchId))->delay(now()->addMinutes(15));

        } catch (\Throwable $e) {
            Log::error("❌ ERROR CRÍTICO en HandleZipMappingJob: " . $e->getMessage());
            $batch->update([
                'status' => 'failed',
                'error_messages' => ["Error crítico: " . $e->getMessage()]
            ]);
        } finally {
            // ✅ NO eliminar tempPath aquí, se hará en FinalizeBatchJob
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
