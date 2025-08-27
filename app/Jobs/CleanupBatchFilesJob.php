<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupBatchFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutos
    public $tries = 2;

    public function __construct(public int $batchId)
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ CleanupBatchFilesJob: Batch {$this->batchId} no encontrado");
            return;
        }

        $batch->logInfo("Iniciando limpieza de archivos");

        $totalCleaned = 0;

        try {
            // ✅ Limpiar según el tipo de batch
            $totalCleaned += match($batch->type) {
                'zip_processing' => $this->cleanupZipProcessingFiles($batch),
                'download_generation' => $this->cleanupDownloadFiles($batch),
                'report_generation' => $this->cleanupReportFiles($batch),
                'image_processing' => $this->cleanupImageProcessingFiles($batch),
                'analysis' => $this->cleanupAnalysisFiles($batch),
                default => $this->cleanupGenericFiles($batch)
            };

            // ✅ Limpiar archivos temporales generales usando StorageManager
            $storageManager = app(StorageManager::class);
            $totalCleaned += $storageManager->cleanupTempFiles($this->batchId);

            $batch->logInfo("Limpieza completada: {$totalCleaned} archivos eliminados");

        } catch (\Throwable $e) {
            $batch->logError("Error en limpieza de archivos: " . $e->getMessage());
            Log::error("❌ CleanupBatchFilesJob error: " . $e->getMessage(), [
                'batch_id' => $this->batchId,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * ✅ Limpiar archivos de procesamiento de ZIPs
     */
    private function cleanupZipProcessingFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Limpiar directorios de extracción temporal
        $tempPatterns = [
            storage_path("app/temp_extract_{$this->batchId}_*"),
            storage_path("app/zip_processing_{$this->batchId}_*")
        ];

        foreach ($tempPatterns as $pattern) {
            $directories = glob($pattern, GLOB_ONLYDIR);
            foreach ($directories as $dir) {
                if ($this->deleteDirectory($dir)) {
                    $cleanedCount++;
                }
            }
        }

        // Limpiar ZIPs originales temporales
        $zipFiles = glob(storage_path("app/uploads/batch_{$this->batchId}_*.zip"));
        foreach ($zipFiles as $zipFile) {
            if (unlink($zipFile)) {
                $cleanedCount++;
            }
        }

        $batch->logInfo("Limpieza ZIP processing: {$cleanedCount} archivos/directorios");
        return $cleanedCount;
    }

    /**
     * ✅ Limpiar archivos de generación de descargas
     */
    private function cleanupDownloadFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Solo limpiar archivos locales temporales, NO los de Wasabi
        $downloadFiles = glob(storage_path("app/downloads/temp_*_batch_{$this->batchId}*"));
        foreach ($downloadFiles as $file) {
            if (unlink($file)) {
                $cleanedCount++;
            }
        }

        // Limpiar directorios temporales de preparación
        $tempDirs = glob(storage_path("app/temp_download_{$this->batchId}_*"), GLOB_ONLYDIR);
        foreach ($tempDirs as $dir) {
            if ($this->deleteDirectory($dir)) {
                $cleanedCount++;
            }
        }

        $batch->logInfo("Limpieza downloads: {$cleanedCount} archivos temporales");
        return $cleanedCount;
    }

    /**
     * ✅ Limpiar archivos de generación de reportes
     */
    private function cleanupReportFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Limpiar PDFs temporales (los finales están en Wasabi)
        $tempPdfFiles = glob(storage_path("app/reports/temp_*_batch_{$this->batchId}*"));
        foreach ($tempPdfFiles as $file) {
            if (unlink($file)) {
                $cleanedCount++;
            }
        }

        // Limpiar directorios de preparación de reportes
        $tempDirs = glob(storage_path("app/temp_report_{$this->batchId}_*"), GLOB_ONLYDIR);
        foreach ($tempDirs as $dir) {
            if ($this->deleteDirectory($dir)) {
                $cleanedCount++;
            }
        }

        $batch->logInfo("Limpieza reports: {$cleanedCount} archivos temporales");
        return $cleanedCount;
    }

    /**
     * ✅ Limpiar archivos de procesamiento de imágenes
     */
    private function cleanupImageProcessingFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Limpiar archivos temporales de procesamiento
        $tempImages = glob(storage_path("app/temp_processing_{$this->batchId}_*"));
        foreach ($tempImages as $file) {
            if (unlink($file)) {
                $cleanedCount++;
            }
        }

        $batch->logInfo("Limpieza image processing: {$cleanedCount} archivos temporales");
        return $cleanedCount;
    }

    /**
     * ✅ Limpiar archivos de análisis
     */
    private function cleanupAnalysisFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Limpiar archivos temporales de análisis
        $tempAnalysis = glob(storage_path("app/temp_analysis_{$this->batchId}_*"));
        foreach ($tempAnalysis as $file) {
            if (unlink($file)) {
                $cleanedCount++;
            }
        }

        $batch->logInfo("Limpieza analysis: {$cleanedCount} archivos temporales");
        return $cleanedCount;
    }

    /**
     * ✅ Limpieza genérica para tipos no específicos
     */
    private function cleanupGenericFiles(UnifiedBatch $batch): int
    {
        $cleanedCount = 0;

        // Limpiar cualquier archivo temporal con el ID del batch
        $patterns = [
            storage_path("app/temp*{$this->batchId}*"),
            storage_path("app/*/temp*{$this->batchId}*")
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
                    $cleanedCount++;
                } elseif (is_dir($file) && $this->deleteDirectory($file)) {
                    $cleanedCount++;
                }
            }
        }

        $batch->logInfo("Limpieza genérica: {$cleanedCount} archivos");
        return $cleanedCount;
    }

    /**
     * ✅ Eliminar directorio recursivamente
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        try {
            $files = array_diff(scandir($dir), ['.', '..']);

            foreach ($files as $file) {
                $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_dir($fullPath)) {
                    $this->deleteDirectory($fullPath);
                } else {
                    unlink($fullPath);
                }
            }

            return rmdir($dir);

        } catch (\Throwable $e) {
            Log::error("Error eliminando directorio {$dir}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ Limpiar archivos expirados automáticamente
     */
    public static function cleanupExpiredBatches(): int
    {
        $expiredBatches = UnifiedBatch::expired()
            ->where('status', '!=', 'cancelled')
            ->get();

        $cleanedCount = 0;

        foreach ($expiredBatches as $batch) {
            // Programar limpieza de cada batch expirado
            self::dispatch($batch->id);

            // Marcar como expirado
            $batch->update([
                'status' => 'cancelled',
                'cancellation_reason' => 'expired'
            ]);

            $cleanedCount++;
        }

        Log::info("🧹 Programada limpieza de {$cleanedCount} batches expirados");
        return $cleanedCount;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ CleanupBatchFilesJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $batch->logError("Falló la limpieza de archivos: " . $exception->getMessage());
        }
    }
}
