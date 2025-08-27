<?php

namespace App\Services;

use App\Models\UnifiedBatch;
use App\Models\Project;
use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StorageManager
{
    private const WASABI_DISK = 'wasabi';
    private const LOCAL_DISK = 'local';

    /**
     * ✅ ESTRUCTURA DE PATHS NUEVA Y ORGANIZADA
     */

    protected function environmentPrefix(): string
    {
        return app()->environment('local') ? 'test/' : '';
    }


    /**
     * 🏗️ Obtener path base del proyecto
     */
    public function getProjectBasePath(int $projectId): string
    {
        return $this->environmentPrefix() . "projects/{$projectId}";
    }

    /**
     * 📁 Obtener path específico por tipo
     */
    public function getProjectPath(int $projectId, string $type = 'original'): string
    {
        $basePath = $this->getProjectBasePath($projectId);

        return match($type) {
            'original' => "{$basePath}/original",
            'processed' => "{$basePath}/processed",
            'temp' => "{$basePath}/temp",
            'reports' => "{$basePath}/reports",
            'downloads' => "{$basePath}/downloads",
            'analytics' => "{$basePath}/analytics",
            default => "{$basePath}/{$type}"
        };
    }

    /**
     * 📦 Obtener path del batch específico
     */
    public function getBatchPath(int $projectId, int $batchId, string $type = 'original'): string
    {
        $projectPath = $this->getProjectPath($projectId, $type);
        return "{$projectPath}/batch_{$batchId}";
    }

    /**
     * 📂 Obtener path de carpeta dentro del batch
     */
    public function getFolderPath(int $projectId, int $batchId, int $folderId, string $type = 'original'): string
    {
        $batchPath = $this->getBatchPath($projectId, $batchId, $type);
        return "{$batchPath}/folder_{$folderId}";
    }

    /**
     * ✅ GESTIÓN DE IMÁGENES
     */

    /**
     * 🖼️ Subir imagen original con nueva estructura
     */
    public function storeOriginalImage(
        \Illuminate\Http\UploadedFile $file,
        int $projectId,
        int $batchId,
        int $folderId,
        string $customName = null
    ): string {
        $folderPath = $this->getFolderPath($projectId, $batchId, $folderId, 'original');
        $filename = $customName ?? $this->generateSafeFilename($file->getClientOriginalName());
        $fullPath = "{$folderPath}/{$filename}";

        // Subir a Wasabi
        $uploaded = Storage::disk(self::WASABI_DISK)->putFileAs(
            dirname($fullPath),
            $file,
            basename($fullPath)
        );

        if (!$uploaded) {
            throw new \Exception("Error subiendo imagen a Wasabi");
        }

        // Crear metadata
        $this->createImageMetadata($fullPath, [
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_at' => now()->toISOString(),
            'project_id' => $projectId,
            'batch_id' => $batchId,
            'folder_id' => $folderId
        ]);

        Log::info("📁 Imagen original almacenada: {$fullPath}");
        return $fullPath;
    }

    /**
     * 🔄 Mover imagen a carpeta procesada
     */
    public function moveToProcessed(Image $image, int $batchId): string
    {
        if (!$image->original_path) {
            throw new \Exception("La imagen no tiene path original");
        }

        $processedPath = $this->getFolderPath(
            $image->project_id,
            $batchId,
            $image->folder_id,
            'processed'
        );

        $filename = basename($image->original_path);
        $newPath = "{$processedPath}/{$filename}";

        // Copiar de original a procesada (no mover, mantener original)
        $wasabi = Storage::disk(self::WASABI_DISK);

        if (!$wasabi->exists($image->original_path)) {
            throw new \Exception("Imagen original no encontrada: {$image->original_path}");
        }

        $content = $wasabi->get($image->original_path);
        $wasabi->put($newPath, $content);

        Log::info("🔄 Imagen movida a procesada: {$image->original_path} → {$newPath}");
        return $newPath;
    }

    /**
     * ✅ GESTIÓN DE ARCHIVOS TEMPORALES
     */

    /**
     * 📁 Crear directorio temporal para batch
     */
    public function createTempDirectory(int $projectId, int $batchId): string
    {
        $tempPath = $this->getBatchPath($projectId, $batchId, 'temp');
        $localTempPath = storage_path("app/temp/batch_{$batchId}_" . time());

        if (!is_dir($localTempPath)) {
            mkdir($localTempPath, 0755, true);
            Log::info("📁 Directorio temporal creado: {$localTempPath}");
        }

        return $localTempPath;
    }

    /**
     * 🧹 Limpiar archivos temporales del batch
     */
    public function cleanupTempFiles(int $batchId): int
    {
        $cleanedCount = 0;

        // Limpiar archivos locales temporales
        $tempPatterns = [
            storage_path("app/temp/batch_{$batchId}_*"),
            storage_path("app/temp_extract_{$batchId}_*"),
            storage_path("app/downloads/temp_*_batch_{$batchId}*")
        ];

        foreach ($tempPatterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_dir($file)) {
                    $this->deleteDirectory($file);
                } else {
                    unlink($file);
                }
                $cleanedCount++;
            }
        }

        Log::info("🧹 Limpiados {$cleanedCount} archivos temporales del batch {$batchId}");
        return $cleanedCount;
    }

    /**
     * ✅ GESTIÓN DE DESCARGAS Y REPORTES
     */

    /**
     * 📦 Almacenar archivo de descarga
     */
    public function storeDownloadFile(
        string $localPath,
        int $projectId,
        int $batchId,
        string $type = 'zip'
    ): string {
        $downloadPath = $this->getBatchPath($projectId, $batchId, 'downloads');
        $filename = basename($localPath);
        $fullPath = "{$downloadPath}/{$filename}";

        $wasabi = Storage::disk(self::WASABI_DISK);

        // Verificar tamaño del archivo
        $fileSizeMB = round(filesize($localPath) / 1024 / 1024, 2);

        if ($fileSizeMB > 100) {
            // Archivos grandes van a Wasabi
            $content = file_get_contents($localPath);
            $wasabi->put($fullPath, $content);

            Log::info("📤 Archivo grande ({$fileSizeMB}MB) almacenado en Wasabi: {$fullPath}");
            return $fullPath; // Path de Wasabi
        } else {
            // Archivos pequeños se quedan en local
            Log::info("📁 Archivo pequeño ({$fileSizeMB}MB) mantenido en local: {$localPath}");
            return $localPath; // Path local
        }
    }

    /**
     * 📄 Almacenar reporte PDF
     */
    public function storeReport(string $localPath, int $projectId, int $batchId): string
    {
        $reportPath = $this->getBatchPath($projectId, $batchId, 'reports');
        $filename = basename($localPath);
        $fullPath = "{$reportPath}/{$filename}";

        $content = file_get_contents($localPath);
        Storage::disk(self::WASABI_DISK)->put($fullPath, $content);

        Log::info("📄 Reporte almacenado: {$fullPath}");
        return $fullPath;
    }

    /**
     * ✅ UTILIDADES Y METADATA
     */

    /**
     * 📋 Crear archivo de metadata
     */
    private function createImageMetadata(string $imagePath, array $metadata): void
    {
        $metadataPath = str_replace(
            ['.jpg', '.jpeg', '.png', '.bmp'],
            '.metadata.json',
            $imagePath
        );

        $jsonContent = json_encode($metadata, JSON_PRETTY_PRINT);
        Storage::disk(self::WASABI_DISK)->put($metadataPath, $jsonContent);
    }

    /**
     * 📊 Generar índice del proyecto
     */
    public function generateProjectIndex(int $projectId): array
    {
        $project = Project::findOrFail($projectId);
        $basePath = $this->getProjectBasePath($projectId);
        $wasabi = Storage::disk(self::WASABI_DISK);

        $index = [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'created_at' => $project->created_at->toISOString()
            ],
            'structure' => [
                'original' => $this->indexDirectory("{$basePath}/original"),
                'processed' => $this->indexDirectory("{$basePath}/processed"),
                'reports' => $this->indexDirectory("{$basePath}/reports"),
                'downloads' => $this->indexDirectory("{$basePath}/downloads")
            ],
            'statistics' => [
                'total_batches' => UnifiedBatch::where('project_id', $projectId)->count(),
                'active_batches' => UnifiedBatch::where('project_id', $projectId)->active()->count(),
                'total_images' => \App\Models\Image::whereHas('folder', fn($q) =>
                $q->where('project_id', $projectId)
                )->count()
            ],
            'generated_at' => now()->toISOString()
        ];

        // Guardar índice
        $indexPath = "{$basePath}/analytics/project_index.json";
        $wasabi->put($indexPath, json_encode($index, JSON_PRETTY_PRINT));

        return $index;
    }

    /**
     * 📁 Indexar directorio
     */
    private function indexDirectory(string $path): array
    {
        $wasabi = Storage::disk(self::WASABI_DISK);

        if (!$wasabi->exists($path)) {
            return ['exists' => false];
        }

        $files = $wasabi->allFiles($path);
        $directories = $wasabi->allDirectories($path);

        return [
            'exists' => true,
            'path' => $path,
            'file_count' => count($files),
            'directory_count' => count($directories),
            'size_mb' => $this->calculateDirectorySize($files),
            'last_modified' => $this->getLastModified($files)
        ];
    }

    /**
     * 🔧 Calcular tamaño de directorio
     */
    private function calculateDirectorySize(array $files): float
    {
        $totalSize = 0;
        $wasabi = Storage::disk(self::WASABI_DISK);

        foreach ($files as $file) {
            try {
                $totalSize += $wasabi->size($file);
            } catch (\Exception $e) {
                // Skip files that can't be read
            }
        }

        return round($totalSize / 1024 / 1024, 2); // MB
    }

    /**
     * ⏰ Obtener última modificación
     */
    private function getLastModified(array $files): ?string
    {
        $wasabi = Storage::disk(self::WASABI_DISK);
        $lastModified = 0;

        foreach ($files as $file) {
            try {
                $modified = $wasabi->lastModified($file);
                if ($modified > $lastModified) {
                    $lastModified = $modified;
                }
            } catch (\Exception $e) {
                // Skip files that can't be read
            }
        }

        return $lastModified ? date('c', $lastModified) : null;
    }

    /**
     * 🔧 UTILIDADES PRIVADAS
     */

    /**
     * Generar nombre de archivo seguro
     */
    private function generateSafeFilename(string $originalName): string
    {
        $info = pathinfo($originalName);
        $name = Str::slug($info['filename'] ?? 'image');
        $extension = strtolower($info['extension'] ?? 'jpg');

        return "{$name}.{$extension}";
    }

    /**
     * Eliminar directorio recursivamente
     */
    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $files = array_diff(scandir($path), ['.', '..']);

        foreach ($files as $file) {
            $fullPath = "{$path}/{$file}";
            is_dir($fullPath) ? $this->deleteDirectory($fullPath) : unlink($fullPath);
        }

        return rmdir($path);
    }

    /**
     * ✅ VERIFICACIONES DE SALUD
     */

    /**
     * 🏥 Verificar salud del storage
     */
    public function checkStorageHealth(): array
    {
        $health = [
            'wasabi_connection' => false,
            'local_space' => null,
            'wasabi_space' => null,
            'errors' => []
        ];

        try {
            // Test Wasabi connection
            Storage::disk(self::WASABI_DISK)->exists('health-check.txt');
            $health['wasabi_connection'] = true;
        } catch (\Exception $e) {
            $health['errors'][] = "Wasabi connection failed: " . $e->getMessage();
        }

        try {
            // Check local space
            $storagePath = storage_path('app');
            $freeBytes = disk_free_space($storagePath);
            $health['local_space'] = [
                'free_gb' => round($freeBytes / 1024 / 1024 / 1024, 2),
                'path' => $storagePath
            ];
        } catch (\Exception $e) {
            $health['errors'][] = "Local space check failed: " . $e->getMessage();
        }

        return $health;
    }

    /**
     * 🧹 LIMPIAR archivos temporales del batch
     */
    public function cleanupBatchFiles(int $projectId, int $batchId): bool
    {
        try {
            $cleaned = 0;
            $errors = 0;

            // ✅ Limpiar archivos temporales en Wasabi
            $tempPath = $this->getBatchPath($projectId, $batchId, 'temp');

            $wasabiDisk = Storage::disk(self::WASABI_DISK);

            if ($wasabiDisk->exists($tempPath)) {
                $files = $wasabiDisk->allFiles($tempPath);

                foreach ($files as $file) {
                    try {
                        $wasabiDisk->delete($file);
                        $cleaned++;
                    } catch (\Throwable $e) {
                        $errors++;
                        Log::warning("No se pudo eliminar archivo temporal {$file}: " . $e->getMessage());
                    }
                }

                // ✅ Eliminar directorio temporal si está vacío
                try {
                    $wasabiDisk->deleteDirectory($tempPath);
                } catch (\Throwable $e) {
                    Log::debug("No se pudo eliminar directorio temporal {$tempPath}: " . $e->getMessage());
                }
            }

            // ✅ Limpiar archivos locales temporales
            $localTempPath = storage_path("app/temp/batch_{$batchId}_*");
            $localFiles = glob($localTempPath);

            foreach ($localFiles as $file) {
                if (is_file($file)) {
                    try {
                        unlink($file);
                        $cleaned++;
                    } catch (\Throwable $e) {
                        $errors++;
                        Log::warning("No se pudo eliminar archivo local {$file}: " . $e->getMessage());
                    }
                }
            }

            Log::info("Limpieza de batch {$batchId} completada: {$cleaned} archivos eliminados, {$errors} errores");

            return $errors === 0;

        } catch (\Throwable $e) {
            Log::error("Error limpiando archivos de batch {$batchId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🧹 LIMPIAR archivos huérfanos del proyecto
     */
    public function cleanupOrphanedFiles(int $projectId): array
    {
        $stats = [
            'temp_files_cleaned' => 0,
            'orphaned_processed_cleaned' => 0,
            'old_downloads_cleaned' => 0,
            'errors' => 0
        ];

        try {
            $wasabiDisk = Storage::disk(self::WASABI_DISK);
            $projectBasePath = $this->getProjectBasePath($projectId);

            // ✅ Limpiar archivos temporales antiguos (más de 7 días)
            $tempPath = $this->getProjectPath($projectId, 'temp');
            if ($wasabiDisk->exists($tempPath)) {
                $tempFiles = $wasabiDisk->allFiles($tempPath);

                foreach ($tempFiles as $file) {
                    try {
                        $lastModified = $wasabiDisk->lastModified($file);
                        if ($lastModified && (time() - $lastModified) > (7 * 24 * 3600)) {
                            $wasabiDisk->delete($file);
                            $stats['temp_files_cleaned']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                    }
                }
            }

            // ✅ Limpiar descargas antiguas (más de 3 días)
            $downloadsPath = $this->getProjectPath($projectId, 'downloads');
            if ($wasabiDisk->exists($downloadsPath)) {
                $downloadFiles = $wasabiDisk->allFiles($downloadsPath);

                foreach ($downloadFiles as $file) {
                    try {
                        $lastModified = $wasabiDisk->lastModified($file);
                        if ($lastModified && (time() - $lastModified) > (3 * 24 * 3600)) {
                            $wasabiDisk->delete($file);
                            $stats['old_downloads_cleaned']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                    }
                }
            }

            // ✅ Limpiar archivos locales temporales antiguos
            $localTempPattern = storage_path("app/temp/*");
            $localTempFiles = glob($localTempPattern);

            foreach ($localTempFiles as $file) {
                if (is_file($file) && (time() - filemtime($file)) > (24 * 3600)) {
                    try {
                        unlink($file);
                        $stats['temp_files_cleaned']++;
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                    }
                }
            }

            Log::info("Limpieza de archivos huérfanos del proyecto {$projectId} completada", $stats);

        } catch (\Throwable $e) {
            Log::error("Error en limpieza de archivos huérfanos del proyecto {$projectId}: " . $e->getMessage());
            $stats['errors']++;
        }

        return $stats;
    }

    /**
     * 📊 OBTENER estadísticas de almacenamiento del proyecto
     */
    public function getProjectStorageStats(int $projectId): array
    {
        $stats = [
            'original_files' => 0,
            'processed_files' => 0,
            'temp_files' => 0,
            'download_files' => 0,
            'total_size_bytes' => 0,
            'total_size_human' => '0 B'
        ];

        try {
            $wasabiDisk = Storage::disk(self::WASABI_DISK);
            $projectBasePath = $this->getProjectBasePath($projectId);

            if (!$wasabiDisk->exists($projectBasePath)) {
                return $stats;
            }

            // ✅ Contar archivos por tipo
            $allFiles = $wasabiDisk->allFiles($projectBasePath);
            $totalSize = 0;

            foreach ($allFiles as $file) {
                try {
                    $size = $wasabiDisk->size($file);
                    $totalSize += $size;

                    if (strpos($file, '/original/') !== false) {
                        $stats['original_files']++;
                    } elseif (strpos($file, '/processed/') !== false) {
                        $stats['processed_files']++;
                    } elseif (strpos($file, '/temp/') !== false) {
                        $stats['temp_files']++;
                    } elseif (strpos($file, '/downloads/') !== false) {
                        $stats['download_files']++;
                    }
                } catch (\Throwable $e) {
                    Log::debug("Error obteniendo tamaño de archivo {$file}: " . $e->getMessage());
                }
            }

            $stats['total_size_bytes'] = $totalSize;
            $stats['total_size_human'] = $this->formatBytes($totalSize);

        } catch (\Throwable $e) {
            Log::error("Error obteniendo estadísticas de proyecto {$projectId}: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * 🔧 Formatear bytes a formato legible
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

}
