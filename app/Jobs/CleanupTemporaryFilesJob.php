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

    public $timeout = 1800; // 30 minutos
    public $tries = 1;

    public function handle()
    {
        Log::info("🧹 Iniciando limpieza de archivos temporales");

        $stats = [
            'downloads_cleaned' => 0,
            'reports_cleaned' => 0,
            'zip_analysis_cleaned' => 0,
            'temp_dirs_cleaned' => 0,
            'space_freed_mb' => 0
        ];

        try {
            // 1. Limpiar descargas expiradas
            $stats['downloads_cleaned'] = $this->cleanupExpiredDownloads();

            // 2. Limpiar reportes expirados
            $stats['reports_cleaned'] = $this->cleanupExpiredReports();

            // 3. Limpiar análisis de ZIP expirados
            $stats['zip_analysis_cleaned'] = $this->cleanupExpiredZipAnalysis();

            // 4. Limpiar directorios temporales huérfanos
            $stats['temp_dirs_cleaned'] = $this->cleanupOrphanedTempDirs();

            // 5. Calcular espacio liberado aproximado
            $stats['space_freed_mb'] = $this->calculateSpaceFreed($stats);

            // 6. Verificar espacio disponible y alertar si es necesario
            $this->checkDiskSpace();

            Log::info("✅ Limpieza completada", $stats);

        } catch (\Exception $e) {
            Log::error("❌ Error en limpieza de archivos temporales: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 🗑️ Limpiar descargas masivas expiradas
     */
    private function cleanupExpiredDownloads(): int
    {
        $expiredBatches = DownloadBatch::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(7)); // Fallback por si no hay expires_at
            })
            ->get();

        $cleaned = 0;
        foreach ($expiredBatches as $batch) {
            if ($batch->file_paths) {
                foreach ($batch->file_paths as $filePath) {
                    if (File::exists($filePath)) {
                        File::delete($filePath);
                        $cleaned++;
                        Log::debug("🗑️ Eliminado: {$filePath}");
                    }
                }
                // Limpiar referencias en BD
                $batch->update(['file_paths' => null]);
            }
        }

        // Eliminar registros muy antiguos (> 30 días)
        DownloadBatch::where('created_at', '<', now()->subDays(30))->delete();

        Log::info("🗑️ Downloads: {$cleaned} archivos eliminados");
        return $cleaned;
    }

    /**
     * 📄 Limpiar reportes expirados
     */
    private function cleanupExpiredReports(): int
    {
        $expiredReports = ReportGeneration::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(14)); // Fallback: 14 días máximo
            })
            ->get();

        $cleaned = 0;
        foreach ($expiredReports as $report) {
            $report->deleteFiles(); // Usa el método existente del modelo
            $cleaned++;
        }

        // Eliminar registros muy antiguos (> 60 días)
        ReportGeneration::where('created_at', '<', now()->subDays(60))->delete();

        Log::info("📄 Reports: {$cleaned} reportes eliminados");
        return $cleaned;
    }

    /**
     * 📦 Limpiar análisis de ZIP expirados
     */
    private function cleanupExpiredZipAnalysis(): int
    {
        $expiredAnalysis = ZipAnalysis::where('created_at', '<', now()->subHours(48)) // 48h para seguridad
        ->get();

        $cleaned = 0;
        foreach ($expiredAnalysis as $analysis) {
            $analysis->cleanup(); // Usa el método existente del modelo
            $analysis->delete();
            $cleaned++;
        }

        Log::info("📦 ZIP Analysis: {$cleaned} análisis eliminados");
        return $cleaned;
    }

    /**
     * 🗂️ Limpiar directorios temporales huérfanos
     */
    private function cleanupOrphanedTempDirs(): int
    {
        $storagePath = storage_path('app');
        $cleaned = 0;

        // Patrones de directorios temporales a limpiar
        $patterns = [
            'temp_zip_*',
            'temp_extract_*',
            'temp_*',
            'tmp/analyzed_*',
            'tmp/temp_*'
        ];

        foreach ($patterns as $pattern) {
            $dirs = glob("{$storagePath}/{$pattern}");

            foreach ($dirs as $dir) {
                $isOld = filemtime($dir) < strtotime('-6 hours'); // Más de 6 horas

                if ($isOld && is_dir($dir)) {
                    File::deleteDirectory($dir);
                    $cleaned++;
                    Log::debug("🗂️ Directorio eliminado: " . basename($dir));
                }
            }
        }

        // Limpiar archivos sueltos en /tmp muy antiguos
        $tempFiles = glob("{$storagePath}/tmp/*");
        foreach ($tempFiles as $file) {
            if (is_file($file) && filemtime($file) < strtotime('-24 hours')) {
                File::delete($file);
                $cleaned++;
            }
        }

        Log::info("🗂️ Temp dirs: {$cleaned} directorios/archivos eliminados");
        return $cleaned;
    }

    /**
     * 💾 Verificar espacio en disco y alertar
     */
    private function checkDiskSpace(): void
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        $totalBytes = disk_total_space($storagePath);

        if (!$freeBytes || !$totalBytes) {
            Log::warning("⚠️ No se pudo obtener información del disco");
            return;
        }

        $freeGB = round($freeBytes / 1024 / 1024 / 1024, 2);
        $totalGB = round($totalBytes / 1024 / 1024 / 1024, 2);
        $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);

        Log::info("💾 Espacio en disco: {$freeGB}GB libres de {$totalGB}GB ({$usedPercent}% usado)");

        // 🚨 Alertas críticas
        if ($freeGB < 5) {
            Log::critical("🚨 ESPACIO CRÍTICO: Solo {$freeGB}GB libres!");
            // Aquí podrías enviar email/Slack/etc.
        } elseif ($freeGB < 10) {
            Log::warning("⚠️ ESPACIO BAJO: Solo {$freeGB}GB libres");
        }

        // 📊 Mostrar uso por directorio principal
        $this->logDirectorySizes();
    }

    /**
     * 📊 Calcular tamaños de directorios principales
     */
    private function logDirectorySizes(): void
    {
        $storagePath = storage_path('app');
        $directories = ['downloads', 'reports', 'temp_zips', 'tmp', 'private'];

        foreach ($directories as $dir) {
            $fullPath = "{$storagePath}/{$dir}";
            if (is_dir($fullPath)) {
                $sizeMB = $this->getDirectorySize($fullPath) / 1024 / 1024;
                Log::info("📁 /{$dir}: " . round($sizeMB, 1) . "MB");
            }
        }
    }

    /**
     * 📏 Calcular tamaño de directorio recursivamente
     */
    private function getDirectorySize($directory): int
    {
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
     * 🧮 Estimar espacio liberado (aproximado)
     */
    private function calculateSpaceFreed($stats): float
    {
        // Estimación basada en archivos típicos:
        // Downloads: ~50MB promedio por ZIP
        // Reports: ~20MB promedio por PDF
        // ZIP Analysis: ~100MB promedio por análisis
        // Temp dirs: ~10MB promedio

        $estimatedMB = ($stats['downloads_cleaned'] * 50) +
            ($stats['reports_cleaned'] * 20) +
            ($stats['zip_analysis_cleaned'] * 100) +
            ($stats['temp_dirs_cleaned'] * 10);

        return round($estimatedMB, 1);
    }

    public function failed(\Exception $exception): void
    {
        Log::error("❌ CleanupTemporaryFilesJob falló: " . $exception->getMessage());
    }
}
