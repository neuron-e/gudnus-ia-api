<?php

namespace App\Jobs;

use App\Models\DownloadBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredDownloadsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutos
    public $tries = 1;

    public function handle()
    {
        Log::info("🧹 Iniciando limpieza de downloads expirados");

        $wasabi = Storage::disk('wasabi');
        $cleanedBatches = 0;
        $cleanedFiles = 0;
        $spaceSavedMB = 0;

        // ✅ Buscar batches expirados
        $expiredBatches = DownloadBatch::where('status', 'completed')
            ->where('expires_at', '<', now())
            ->whereNotNull('file_paths')
            ->get();

        foreach ($expiredBatches as $batch) {
            try {
                $filePaths = is_array($batch->file_paths) ? $batch->file_paths : json_decode($batch->file_paths, true);

                if (!$filePaths) {
                    continue;
                }

                foreach ($filePaths as $filePath) {
                    if ($wasabi->exists($filePath)) {
                        try {
                            // ✅ Obtener tamaño antes de eliminar
                            $fileSize = $wasabi->size($filePath);
                            $spaceSavedMB += ($fileSize / 1024 / 1024);

                            // ✅ Eliminar archivo
                            $wasabi->delete($filePath);
                            $cleanedFiles++;

                            Log::debug("🗑️ Eliminado: {$filePath} (" . round($fileSize/1024/1024, 1) . "MB)");
                        } catch (\Exception $e) {
                            Log::warning("⚠️ Error eliminando {$filePath}: " . $e->getMessage());
                        }
                    }
                }

                // ✅ Marcar batch como expirado
                $batch->update([
                    'status' => 'expired',
                    'file_paths' => null,
                    'expired_at' => now()
                ]);

                $cleanedBatches++;

            } catch (\Exception $e) {
                Log::error("❌ Error limpiando batch {$batch->id}: " . $e->getMessage());
            }
        }

        // ✅ Limpiar también directorios vacíos en Wasabi
        $this->cleanupEmptyDirectories($wasabi);

        Log::info("✅ Limpieza completada", [
            'batches_cleaned' => $cleanedBatches,
            'files_deleted' => $cleanedFiles,
            'space_saved_mb' => round($spaceSavedMB, 1),
            'total_batches_checked' => $expiredBatches->count()
        ]);
    }

    /**
     * ✅ Limpiar directorios vacíos de proyectos antiguos
     */
    private function cleanupEmptyDirectories($wasabi): void
    {
        try {
            $directories = $wasabi->directories('downloads');
            $cleanedDirs = 0;

            foreach ($directories as $dir) {
                $files = $wasabi->files($dir);

                // Si el directorio está vacío y es antiguo (más de 30 días)
                if (empty($files)) {
                    // Extraer fecha del directorio si es posible
                    if (preg_match('/project_(\d+)/', $dir, $matches)) {
                        try {
                            $wasabi->deleteDirectory($dir);
                            $cleanedDirs++;
                            Log::debug("🗑️ Directorio vacío eliminado: {$dir}");
                        } catch (\Exception $e) {
                            Log::warning("⚠️ Error eliminando directorio {$dir}: " . $e->getMessage());
                        }
                    }
                }
            }

            if ($cleanedDirs > 0) {
                Log::info("🧹 Directorios vacíos eliminados: {$cleanedDirs}");
            }

        } catch (\Exception $e) {
            Log::warning("⚠️ Error en limpieza de directorios: " . $e->getMessage());
        }
    }

    /**
     * ✅ Programar automáticamente cada día
     */
    public static function scheduleDaily(): void
    {
        // Añadir en app/Console/Kernel.php en el método schedule():
        // $schedule->job(new CleanupExpiredDownloadsJob)->daily();
    }
}
