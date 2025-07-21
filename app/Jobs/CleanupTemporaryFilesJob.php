<?php

namespace App\Jobs;

use App\Models\DownloadBatch;
use App\Models\ReportGeneration;
use App\Models\ZipAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupTemporaryFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA SERVIDOR POTENTE
    public $timeout = 3600; // 1 hora (era 30 minutos)
    public $tries = 1;      // Solo 1 intento

    public function handle()
    {
        $startTime = microtime(true);

        Log::info("🧹 [OPTIMIZADO] Iniciando limpieza de archivos temporales", [
            'server_specs' => '8vCPU/32GB',
            'memory_limit' => ini_get('memory_limit')
        ]);

        $stats = [
            'downloads_cleaned' => 0,
            'reports_cleaned' => 0,
            'zip_analysis_cleaned' => 0,
            'temp_dirs_cleaned' => 0,
            'space_freed_mb' => 0,
            'files_processed' => 0
        ];

        try {
            // ✅ VERIFICAR ESPACIO INICIAL
            $initialSpace = $this->getDiskSpaceInfo();

            // ✅ LIMPIEZA OPTIMIZADA EN PARALELO LÓGICO
            $stats['downloads_cleaned'] = $this->cleanupExpiredDownloadsOptimized();
            $stats['reports_cleaned'] = $this->cleanupExpiredReportsOptimized();
            $stats['zip_analysis_cleaned'] = $this->cleanupExpiredZipAnalysisOptimized();
            $stats['temp_dirs_cleaned'] = $this->cleanupOrphanedTempDirsOptimized();

            // ✅ CALCULAR ESPACIO LIBERADO REAL
            $finalSpace = $this->getDiskSpaceInfo();
            $stats['space_freed_mb'] = round(($finalSpace['free_gb'] - $initialSpace['free_gb']) * 1024, 1);

            // ✅ VERIFICACIÓN FINAL DE ESPACIO
            $this->checkDiskSpaceOptimized();

            $processingTime = round(microtime(true) - $startTime, 2);

            Log::info("✅ [OPTIMIZADO] Limpieza completada", array_merge($stats, [
                'processing_time' => $processingTime . 's',
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]));

        } catch (\Exception $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ Error en limpieza optimizada", [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime . 's',
                'stats_partial' => $stats
            ]);
            throw $e;
        }
    }

    /**
     * ✅ LIMPIEZA OPTIMIZADA DE DESCARGAS EXPIRADAS
     */
    private function cleanupExpiredDownloadsOptimized(): int
    {
        Log::info("🗑️ Limpiando descargas expiradas...");

        $expiredBatches = DownloadBatch::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(7));
            })
            ->orderBy('created_at', 'asc') // ✅ Procesar más antiguos primero
            ->get();

        $cleaned = 0;
        $spaceCleaned = 0;

        foreach ($expiredBatches as $batch) {
            try {
                if ($batch->file_paths) {
                    foreach ($batch->file_paths as $filePath) {
                        $size = $this->getFileSize($filePath);

                        if ($this->deleteFileSecurely($filePath)) {
                            $cleaned++;
                            $spaceCleaned += $size;
                        }
                    }

                    // ✅ LIMPIAR REFERENCIAS EN BD
                    $batch->update(['file_paths' => null]);
                }

                // ✅ LOG PROGRESO CADA 50 BATCHES
                if ($cleaned % 50 === 0 && $cleaned > 0) {
                    Log::info("📊 Progreso downloads: {$cleaned} archivos, " . round($spaceCleaned / 1024 / 1024, 1) . "MB liberados");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error limpiando batch {$batch->id}: " . $e->getMessage());
            }
        }

        // ✅ ELIMINAR REGISTROS ANTIGUOS EN LOTES
        $deletedRecords = DownloadBatch::where('created_at', '<', now()->subDays(30))
            ->delete();

        Log::info("✅ Downloads limpieza: {$cleaned} archivos, {$deletedRecords} registros eliminados");
        return $cleaned;
    }

    /**
     * ✅ LIMPIEZA OPTIMIZADA DE REPORTES EXPIRADOS
     */
    private function cleanupExpiredReportsOptimized(): int
    {
        Log::info("📄 Limpiando reportes expirados...");

        $expiredReports = ReportGeneration::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(14));
            })
            ->orderBy('created_at', 'asc')
            ->get();

        $cleaned = 0;

        foreach ($expiredReports as $report) {
            try {
                $report->deleteFiles(); // ✅ Usar método existente del modelo
                $cleaned++;

                if ($cleaned % 25 === 0 && $cleaned > 0) {
                    Log::info("📊 Progreso reports: {$cleaned} reportes eliminados");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error limpiando reporte {$report->id}: " . $e->getMessage());
            }
        }

        // ✅ ELIMINAR REGISTROS MUY ANTIGUOS
        $deletedRecords = ReportGeneration::where('created_at', '<', now()->subDays(60))
            ->delete();

        Log::info("✅ Reports limpieza: {$cleaned} reportes, {$deletedRecords} registros eliminados");
        return $cleaned;
    }

    /**
     * ✅ LIMPIEZA OPTIMIZADA DE ANÁLISIS ZIP EXPIRADOS
     */
    private function cleanupExpiredZipAnalysisOptimized(): int
    {
        Log::info("📦 Limpiando análisis ZIP expirados...");

        $expiredAnalysis = ZipAnalysis::where('created_at', '<', now()->subHours(72)) // ✅ 72h (era 48h)
        ->orderBy('created_at', 'asc')
            ->get();

        $cleaned = 0;

        foreach ($expiredAnalysis as $analysis) {
            try {
                $analysis->cleanup(); // ✅ Usar método existente del modelo
                $analysis->delete();
                $cleaned++;

                if ($cleaned % 10 === 0 && $cleaned > 0) {
                    Log::info("📊 Progreso ZIP analysis: {$cleaned} análisis eliminados");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error limpiando análisis {$analysis->id}: " . $e->getMessage());
            }
        }

        Log::info("✅ ZIP analysis limpieza: {$cleaned} análisis eliminados");
        return $cleaned;
    }

    /**
     * ✅ LIMPIEZA OPTIMIZADA DE DIRECTORIOS TEMPORALES HUÉRFANOS
     */
    private function cleanupOrphanedTempDirsOptimized(): int
    {
        Log::info("🗂️ Limpiando directorios temporales huérfanos...");

        $storagePath = storage_path('app');
        $cleaned = 0;

        // ✅ PATRONES OPTIMIZADOS DE BÚSQUEDA
        $patterns = [
            'temp_zip_*' => 12,      // 12 horas
            'temp_extract_*' => 12,  // 12 horas
            'temp_crop_*' => 6,      // 6 horas
            'tmp/analyzed_*' => 24,  // 24 horas
            'tmp/temp_*' => 6,       // 6 horas
            'downloads/*.zip' => 48, // ✅ ZIPs de descarga antiguos: 48h
        ];

        foreach ($patterns as $pattern => $maxAgeHours) {
            $paths = glob("{$storagePath}/{$pattern}");
            $cutoffTime = strtotime("-{$maxAgeHours} hours");

            foreach ($paths as $path) {
                try {
                    $isOld = filemtime($path) < $cutoffTime;

                    if ($isOld) {
                        if (is_dir($path)) {
                            File::deleteDirectory($path);
                        } else {
                            File::delete($path);
                        }
                        $cleaned++;

                        Log::debug("🗑️ Eliminado: " . basename($path) . " (edad: " . round((time() - filemtime($path)) / 3600, 1) . "h)");
                    }

                } catch (\Exception $e) {
                    Log::warning("⚠️ Error eliminando {$path}: " . $e->getMessage());
                }
            }
        }

        // ✅ LIMPIEZA ESPECIAL DE ARCHIVOS TEMPORALES PEQUEÑOS
        $this->cleanupSmallTempFiles($storagePath);

        Log::info("✅ Temp dirs limpieza: {$cleaned} elementos eliminados");
        return $cleaned;
    }

    /**
     * ✅ LIMPIEZA DE ARCHIVOS TEMPORALES PEQUEÑOS
     */
    private function cleanupSmallTempFiles(string $storagePath): void
    {
        $tempDirs = ['tmp', 'temp', 'cache'];

        foreach ($tempDirs as $dir) {
            $fullPath = "{$storagePath}/{$dir}";
            if (!is_dir($fullPath)) continue;

            try {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
                );

                $cleaned = 0;
                foreach ($files as $file) {
                    if ($file->isFile() &&
                        $file->getMTime() < strtotime('-4 hours') &&
                        $file->getSize() < 10 * 1024 * 1024) { // < 10MB

                        @unlink($file->getPathname());
                        $cleaned++;
                    }
                }

                if ($cleaned > 0) {
                    Log::info("🧹 Limpiados {$cleaned} archivos pequeños en /{$dir}");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error limpiando directorio {$dir}: " . $e->getMessage());
            }
        }
    }

    /**
     * ✅ VERIFICACIÓN OPTIMIZADA DE ESPACIO EN DISCO
     */
    private function checkDiskSpaceOptimized(): void
    {
        $spaceInfo = $this->getDiskSpaceInfo();

        Log::info("💾 Estado del disco", [
            'free_gb' => $spaceInfo['free_gb'],
            'total_gb' => $spaceInfo['total_gb'],
            'used_percent' => $spaceInfo['used_percent']
        ]);

        // ✅ ALERTAS OPTIMIZADAS PARA SERVIDOR POTENTE
        if ($spaceInfo['free_gb'] < 10) {
            Log::critical("🚨 ESPACIO CRÍTICO: Solo {$spaceInfo['free_gb']}GB libres!");
            $this->emergencyCleanup();
        } elseif ($spaceInfo['free_gb'] < 20) {
            Log::warning("⚠️ ESPACIO BAJO: Solo {$spaceInfo['free_gb']}GB libres");
            $this->moderateCleanup();
        }

        // ✅ MOSTRAR USO DETALLADO
        $this->logDirectorySizesOptimized();
    }

    /**
     * ✅ OBTENER INFORMACIÓN DE ESPACIO EN DISCO
     */
    private function getDiskSpaceInfo(): array
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        $totalBytes = disk_total_space($storagePath);

        if (!$freeBytes || !$totalBytes) {
            return ['free_gb' => 0, 'total_gb' => 0, 'used_percent' => 100];
        }

        $freeGB = round($freeBytes / 1024 / 1024 / 1024, 2);
        $totalGB = round($totalBytes / 1024 / 1024 / 1024, 2);
        $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);

        return [
            'free_gb' => $freeGB,
            'total_gb' => $totalGB,
            'used_percent' => $usedPercent
        ];
    }

    /**
     * ✅ LOG OPTIMIZADO DE TAMAÑOS DE DIRECTORIOS
     */
    private function logDirectorySizesOptimized(): void
    {
        $storagePath = storage_path('app');
        $directories = ['downloads', 'reports', 'temp_zips', 'tmp', 'uploads', 'cache'];

        $sizes = [];
        foreach ($directories as $dir) {
            $fullPath = "{$storagePath}/{$dir}";
            if (is_dir($fullPath)) {
                $sizeMB = $this->getDirectorySizeOptimized($fullPath) / 1024 / 1024;
                $sizes[$dir] = round($sizeMB, 1);
            }
        }

        // ✅ ORDENAR POR TAMAÑO DESCENDENTE
        arsort($sizes);

        Log::info("📊 Uso por directorio:", $sizes);
    }

    /**
     * ✅ CÁLCULO OPTIMIZADO DE TAMAÑO DE DIRECTORIO
     */
    private function getDirectorySizeOptimized($directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            Log::warning("No se pudo calcular tamaño de {$directory}: " . $e->getMessage());
        }

        return $size;
    }

    /**
     * ✅ LIMPIEZA DE EMERGENCIA
     */
    private function emergencyCleanup(): void
    {
        Log::warning("🚨 Ejecutando limpieza de emergencia...");

        // ✅ ELIMINAR ARCHIVOS MUY ANTIGUOS AGRESIVAMENTE
        $patterns = [
            storage_path('app/downloads/*') => 24,     // 24h
            storage_path('app/tmp/*') => 2,            // 2h
            storage_path('app/temp_*') => 6,           // 6h
        ];

        $cleaned = 0;
        foreach ($patterns as $pattern => $maxAgeHours) {
            $files = glob($pattern);
            $cutoff = strtotime("-{$maxAgeHours} hours");

            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    if (is_dir($file)) {
                        File::deleteDirectory($file);
                    } else {
                        File::delete($file);
                    }
                    $cleaned++;
                }
            }
        }

        Log::info("🚨 Limpieza de emergencia: {$cleaned} elementos eliminados");
    }

    /**
     * ✅ LIMPIEZA MODERADA
     */
    private function moderateCleanup(): void
    {
        Log::info("⚠️ Ejecutando limpieza moderada...");

        // ✅ LIMPIAR ARCHIVOS INTERMEDIOS
        $tempFiles = glob(storage_path('app/tmp/*.jpg'));
        $cleaned = 0;

        foreach ($tempFiles as $file) {
            if (filemtime($file) < strtotime('-1 hour')) {
                @unlink($file);
                $cleaned++;
            }
        }

        Log::info("⚠️ Limpieza moderada: {$cleaned} archivos temporales eliminados");
    }

    // ✅ MÉTODOS AUXILIARES

    private function getFileSize(string $filePath): int
    {
        if (str_starts_with($filePath, 'downloads/')) {
            $wasabi = Storage::disk('wasabi');
            return $wasabi->exists($filePath) ? $wasabi->size($filePath) : 0;
        } else {
            return file_exists($filePath) ? filesize($filePath) : 0;
        }
    }

    private function deleteFileSecurely(string $filePath): bool
    {
        try {
            if (str_starts_with($filePath, 'downloads/')) {
                $wasabi = Storage::disk('wasabi');
                if ($wasabi->exists($filePath)) {
                    return $wasabi->delete($filePath);
                }
            } else {
                if (file_exists($filePath)) {
                    return unlink($filePath);
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::warning("Error eliminando {$filePath}: " . $e->getMessage());
            return false;
        }
    }

    public function failed(\Exception $exception): void
    {
        Log::error("❌ CleanupTemporaryFilesJob falló", [
            'error' => $exception->getMessage(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
        ]);
    }
}
