<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ✅ PLACEHOLDER: GenerateDownloadChunkJob
 * TODO: Implementar en Fase 3 con lógica completa de descarga
 */
class GenerateDownloadChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hora
    public $tries = 2;

    public function __construct(
        public array $imageIds,
        public string $downloadType,
        public int $batchId,
        public int $chunkIndex,
        public int $totalChunks
    ) {
        $this->onQueue('downloads');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            return;
        }

        if ($batch->isCancelled()) {
            $batch->decrementActiveJobs();
            return;
        }

        $batch->logInfo("📥 PLACEHOLDER: Generando descarga chunk {$this->chunkIndex}/{$this->totalChunks} tipo {$this->downloadType}");

        try {
            // ✅ Simular procesamiento de descarga
            sleep(rand(5, 15)); // 5-15 segundos

            // ✅ Simular archivo generado
            $fakeFilePath = "downloads/batch_{$this->batchId}_chunk_{$this->chunkIndex}.zip";

            $batch->addGeneratedFile($fakeFilePath, 'download_chunk');

            // ✅ Actualizar progreso
            $itemsInChunk = count($this->imageIds);
            for ($i = 0; $i < $itemsInChunk; $i++) {
                $batch->incrementProcessed();
            }

            $batch->decrementActiveJobs();
            $batch->logInfo("✅ Chunk de descarga {$this->chunkIndex} completado");

        } catch (\Throwable $e) {
            $batch->logError("Error en descarga chunk {$this->chunkIndex}: " . $e->getMessage());
            $batch->incrementFailed("Error generando descarga: " . $e->getMessage());
            $batch->decrementActiveJobs();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("FAILED GenerateDownloadChunkJob chunk {$this->chunkIndex}: " . $exception->getMessage());
            $batch->decrementActiveJobs();
        }
    }
}

/**
 * ✅ PLACEHOLDER: GenerateReportChunkJob
 * TODO: Implementar en Fase 3 con lógica completa de reportes
 */
class GenerateReportChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 horas
    public $tries = 2;

    public function __construct(
        public array $imageIds,
        public string $reportType,
        public int $batchId,
        public int $chunkIndex,
        public int $totalChunks
    ) {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            return;
        }

        if ($batch->isCancelled()) {
            $batch->decrementActiveJobs();
            return;
        }

        $batch->logInfo("📄 PLACEHOLDER: Generando reporte chunk {$this->chunkIndex}/{$this->totalChunks} tipo {$this->reportType}");

        try {
            // ✅ Simular generación de reporte
            sleep(rand(10, 30)); // 10-30 segundos

            // ✅ Simular PDF generado
            $fakePdfPath = "reports/batch_{$this->batchId}_chunk_{$this->chunkIndex}.pdf";

            $batch->addGeneratedFile($fakePdfPath, 'report_chunk');

            // ✅ Actualizar progreso
            $itemsInChunk = count($this->imageIds);
            for ($i = 0; $i < $itemsInChunk; $i++) {
                $batch->incrementProcessed();
            }

            $batch->decrementActiveJobs();
            $batch->logInfo("✅ Chunk de reporte {$this->chunkIndex} completado");

            // ✅ Si es el último chunk, combinar PDFs
            if ($this->chunkIndex === $this->totalChunks) {
                $this->combineFinalReport($batch);
            }

        } catch (\Throwable $e) {
            $batch->logError("Error en reporte chunk {$this->chunkIndex}: " . $e->getMessage());
            $batch->incrementFailed("Error generando reporte: " . $e->getMessage());
            $batch->decrementActiveJobs();
        }
    }

    private function combineFinalReport(UnifiedBatch $batch): void
    {
        try {
            // ✅ Simular combinación de PDFs
            sleep(5);

            $finalReportPath = "reports/final_report_batch_{$this->batchId}.pdf";
            $batch->addGeneratedFile($finalReportPath, 'final_report');

            // ✅ Crear URL de descarga temporal
            $downloadUrl = "/api/downloads/report/{$this->batchId}";
            $batch->update([
                'download_url' => $downloadUrl,
                'expires_at' => now()->addDays(7) // 7 días para reportes
            ]);

            $batch->logInfo("📄 Reporte final combinado disponible: {$finalReportPath}");

        } catch (\Throwable $e) {
            $batch->logError("Error combinando reporte final: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("FAILED GenerateReportChunkJob chunk {$this->chunkIndex}: " . $exception->getMessage());
            $batch->decrementActiveJobs();
        }
    }
}

// ==================== JOBS DE APOYO ====================

/**
 * ✅ Job para combinar resultados finales de descargas
 */
class FinalizeDownloadBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutos

    public function __construct(public int $batchId)
    {
        $this->onQueue('downloads');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch || !$batch->isCompleted()) {
            return;
        }

        $batch->logInfo("🎯 Finalizando batch de descarga");

        try {
            // ✅ Crear URL de descarga temporal
            $downloadUrl = "/api/downloads/batch/{$this->batchId}";

            $batch->update([
                'download_url' => $downloadUrl,
                'expires_at' => now()->addDays(3) // 3 días para descargas
            ]);

            $batch->logInfo("✅ Descarga finalizada - URL disponible por 3 días");

        } catch (\Throwable $e) {
            $batch->logError("Error finalizando descarga: " . $e->getMessage());
        }
    }
}
