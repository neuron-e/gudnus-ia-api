<?php

namespace App\Console\Commands;

use App\Jobs\CleanupTemporaryFilesJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanupTemporaryFilesCommand extends Command
{
    protected $signature = 'app:cleanup-temp-files
                           {--force : Forzar limpieza sin confirmación}
                           {--dry-run : Mostrar qué se eliminaría sin hacerlo}
                           {--check-disk : Solo verificar espacio en disco}';

    protected $description = 'Limpia archivos temporales, ZIPs y reportes expirados para liberar espacio en disco';

    public function handle()
    {
        $this->info("🧹 Iniciando limpieza de archivos temporales...");

        // Solo verificar espacio en disco
        if ($this->option('check-disk')) {
            $this->checkDiskSpaceOnly();
            return;
        }

        // Modo dry-run: mostrar qué se eliminaría
        if ($this->option('dry-run')) {
            $this->dryRun();
            return;
        }

        // Verificar espacio crítico
        $freeSpace = $this->getCurrentFreeSpace();
        if ($freeSpace < 5 && !$this->option('force')) {
            $this->error("🚨 ESPACIO CRÍTICO: Solo {$freeSpace}GB libres!");
            $confirmed = $this->confirm('¿Continuar con la limpieza de emergencia?');
            if (!$confirmed) {
                $this->info("Operación cancelada.");
                return;
            }
        }

        // Confirmar antes de proceder (excepto si es force)
        if (!$this->option('force')) {
            $confirmed = $this->confirm('¿Proceder con la limpieza de archivos temporales?');
            if (!$confirmed) {
                $this->info("Operación cancelada.");
                return;
            }
        }

        // Ejecutar limpieza
        $this->info("⚡ Ejecutando job de limpieza...");

        try {
            // Ejecutar directamente en lugar de encolar para comandos manuales
            $job = new CleanupTemporaryFilesJob();
            $job->handle();

            $this->info("✅ Limpieza completada exitosamente!");

            // Mostrar espacio liberado
            $newFreeSpace = $this->getCurrentFreeSpace();
            $this->info("💾 Espacio libre: {$newFreeSpace}GB");

        } catch (\Exception $e) {
            $this->error("❌ Error durante la limpieza: " . $e->getMessage());
            Log::error("CleanupCommand failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * 🔍 Solo verificar espacio en disco
     */
    private function checkDiskSpaceOnly(): void
    {
        $this->info("💾 Verificando espacio en disco...");

        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        $totalBytes = disk_total_space($storagePath);

        if (!$freeBytes || !$totalBytes) {
            $this->error("❌ No se pudo obtener información del disco");
            return;
        }

        $freeGB = round($freeBytes / 1024 / 1024 / 1024, 2);
        $totalGB = round($totalBytes / 1024 / 1024 / 1024, 2);
        $usedGB = round(($totalBytes - $freeBytes) / 1024 / 1024 / 1024, 2);
        $usedPercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);

        // Información general
        $this->table(['Métrica', 'Valor'], [
            ['Espacio total', "{$totalGB}GB"],
            ['Espacio usado', "{$usedGB}GB ({$usedPercent}%)"],
            ['Espacio libre', "{$freeGB}GB"],
        ]);

        // Estado del disco
        if ($freeGB < 5) {
            $this->error("🚨 CRÍTICO: Menos de 5GB libres!");
        } elseif ($freeGB < 10) {
            $this->warn("⚠️  ADVERTENCIA: Menos de 10GB libres");
        } else {
            $this->info("✅ Espacio en disco OK");
        }

        // Mostrar tamaños por directorio
        $this->info("\n📁 Uso por directorio:");
        $this->showDirectorySizes();
    }

    /**
     * 👀 Modo dry-run: mostrar qué se eliminaría
     */
    private function dryRun(): void
    {
        $this->info("👀 Modo DRY-RUN: Mostrando qué se eliminaría (sin hacer cambios)...");

        $stats = [
            'downloads' => 0,
            'reports' => 0,
            'zip_analysis' => 0,
            'temp_dirs' => 0
        ];

        // Contar descargas expiradas
        $expiredDownloads = \App\Models\DownloadBatch::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(7));
            })
            ->get();

        foreach ($expiredDownloads as $batch) {
            if ($batch->file_paths) {
                foreach ($batch->file_paths as $filePath) {
                    if (File::exists($filePath)) {
                        $stats['downloads']++;
                    }
                }
            }
        }

        // Contar reportes expirados
        $expiredReports = \App\Models\ReportGeneration::where('status', 'completed')
            ->where(function($q) {
                $q->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(14));
            })
            ->count();
        $stats['reports'] = $expiredReports;

        // Contar análisis de ZIP expirados
        $expiredZipAnalysis = \App\Models\ZipAnalysis::where('created_at', '<', now()->subHours(48))
            ->count();
        $stats['zip_analysis'] = $expiredZipAnalysis;

        // Contar directorios temporales
        $tempDirs = 0;
        $patterns = ['temp_zip_*', 'temp_extract_*', 'temp_*', 'tmp/analyzed_*'];
        foreach ($patterns as $pattern) {
            $dirs = glob(storage_path("app/{$pattern}"));
            foreach ($dirs as $dir) {
                if (filemtime($dir) < strtotime('-6 hours')) {
                    $tempDirs++;
                }
            }
        }
        $stats['temp_dirs'] = $tempDirs;

        // Mostrar resultados
        $this->table(['Tipo', 'Archivos/Directorios a eliminar'], [
            ['Descargas expiradas', $stats['downloads']],
            ['Reportes expirados', $stats['reports']],
            ['Análisis ZIP expirados', $stats['zip_analysis']],
            ['Directorios temporales', $stats['temp_dirs']],
        ]);

        $total = array_sum($stats);
        if ($total > 0) {
            $this->info("📊 Total a eliminar: {$total} elementos");
            $this->info("Para ejecutar realmente: php artisan app:cleanup-temp-files --force");
        } else {
            $this->info("✅ No hay archivos para limpiar");
        }
    }

    /**
     * 📏 Obtener espacio libre actual
     */
    private function getCurrentFreeSpace(): float
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        return $freeBytes ? round($freeBytes / 1024 / 1024 / 1024, 2) : 0;
    }

    /**
     * 📊 Mostrar tamaños de directorios
     */
    private function showDirectorySizes(): void
    {
        $storagePath = storage_path('app');
        $directories = ['downloads', 'reports', 'temp_zips', 'tmp', 'private'];

        $data = [];
        foreach ($directories as $dir) {
            $fullPath = "{$storagePath}/{$dir}";
            if (is_dir($fullPath)) {
                $sizeMB = $this->getDirectorySize($fullPath) / 1024 / 1024;
                $data[] = ["/{$dir}", round($sizeMB, 1) . "MB"];
            } else {
                $data[] = ["/{$dir}", "No existe"];
            }
        }

        $this->table(['Directorio', 'Tamaño'], $data);
    }

    /**
     * 📏 Calcular tamaño de directorio
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
            // Ignorar errores de permisos
        }

        return $size;
    }
}
