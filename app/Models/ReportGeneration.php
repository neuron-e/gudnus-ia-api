<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ReportGeneration extends Model
{
    // ✅ CAMPOS ORIGINALES - Sin storage_type
    protected $fillable = [
        'project_id',
        'status', // processing, completed, failed
        'user_email',
        'total_images',
        'processed_images',
        'file_path', // o array de paths (pueden ser locales o Wasabi)
        'error_message',
        'completed_at',
        'expires_at', // para auto-cleanup
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'file_path' => 'array', // Para manejar múltiples PDFs
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * ✅ Verificar si el reporte está listo para descarga
     */
    public function isReady(): bool
    {
        return $this->status === 'completed' && $this->file_path && !$this->hasExpired();
    }

    /**
     * ✅ Obtener URL(s) de descarga (detecta automáticamente el storage)
     */
    public function getDownloadUrls(): array
    {
        if (!$this->isReady()) return [];

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];

        return collect($paths)->map(function ($path) {
            return [
                'filename' => basename($path),
                'url' => route('reports.download', ['id' => $this->id, 'file' => basename($path)]),
                'storage_type' => $this->isWasabiPath($path) ? 'wasabi' : 'local',
                'size' => $this->getFileSize($path),
                'size_mb' => round($this->getFileSize($path) / 1024 / 1024, 2)
            ];
        })->toArray();
    }

    /**
     * 🆕 NUEVO: Mover reporte a Wasabi si es grande (SIN CAMPO storage_type)
     */
    public function moveToWasabiIfNeeded(): bool
    {
        if ($this->status !== 'completed' || !$this->file_path) {
            return false;
        }

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];
        $totalSizeMB = 0;
        $newPaths = [];

        // ✅ Calcular tamaño total y filtrar archivos locales
        foreach ($paths as $path) {
            if ($this->isWasabiPath($path)) {
                // Ya está en Wasabi
                $newPaths[] = $path;
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                $totalSizeMB += Storage::disk('local')->size($path) / 1024 / 1024;
            }
        }

        Log::info("📊 Reporte {$this->id}: Tamaño total {$totalSizeMB}MB");

        // ✅ Si es > 50MB, mover a Wasabi
        if ($totalSizeMB > 50) {
            Log::info("📤 Moviendo reporte {$this->id} a Wasabi ({$totalSizeMB}MB)");

            $wasabi = Storage::disk('wasabi');
            $moveSuccess = true;

            foreach ($paths as $path) {
                if ($this->isWasabiPath($path)) {
                    $newPaths[] = $path;
                    continue;
                }

                if (!Storage::disk('local')->exists($path)) {
                    Log::warning("⚠️ Archivo local no encontrado: {$path}");
                    $newPaths[] = $path;
                    continue;
                }

                try {
                    // ✅ Generar ruta en Wasabi
                    $fileName = basename($path);
                    $wasabiPath = "reports/project_{$this->project_id}/{$fileName}";

                    // ✅ Mover a Wasabi usando stream
                    $stream = Storage::disk('local')->readStream($path);
                    if (!$stream) {
                        throw new \Exception("No se pudo leer el archivo local");
                    }

                    $success = $wasabi->writeStream($wasabiPath, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if (!$success) {
                        throw new \Exception("Falló la subida a Wasabi");
                    }

                    // ✅ Verificar integridad
                    $localSize = Storage::disk('local')->size($path);
                    $wasabiSize = $wasabi->size($wasabiPath);

                    if (abs($localSize - $wasabiSize) > 1024) { // Tolerancia de 1KB
                        throw new \Exception("Tamaños no coinciden: local={$localSize}, wasabi={$wasabiSize}");
                    }

                    // ✅ Eliminar archivo local
                    Storage::disk('local')->delete($path);

                    // ✅ Usar nueva ruta
                    $newPaths[] = $wasabiPath;

                    Log::info("✅ Reporte movido a Wasabi: {$fileName}");

                } catch (\Exception $e) {
                    Log::error("❌ Error moviendo reporte a Wasabi: " . $e->getMessage());
                    $newPaths[] = $path; // Mantener local si falla
                    $moveSuccess = false;
                }
            }

            // ✅ Actualizar paths (SIN storage_type)
            if ($moveSuccess) {
                $this->update([
                    'file_path' => count($newPaths) === 1 ? $newPaths[0] : $newPaths
                ]);

                Log::info("✅ Reporte {$this->id} movido completamente a Wasabi");
                return true;
            }
        }

        return false;
    }

    /**
     * ✅ Calcular progreso en porcentaje
     */
    public function getProgressPercentage(): int
    {
        if ($this->total_images <= 0) return 0;
        return min(100, round(($this->processed_images / $this->total_images) * 100));
    }

    /**
     * ✅ Verificar si ha expirado
     */
    public function hasExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * ✅ Establecer expiración (default: 7 días)
     */
    public function setExpiration(int $days = 7): void
    {
        $this->update(['expires_at' => now()->addDays($days)]);
    }

    /**
     * ✅ MEJORADO: Eliminar archivos asociados (local y Wasabi)
     */
    public function deleteFiles(): void
    {
        if (!$this->file_path) return;

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];
        $wasabi = Storage::disk('wasabi');

        foreach ($paths as $path) {
            try {
                if ($this->isWasabiPath($path)) {
                    // Archivo en Wasabi
                    if ($wasabi->exists($path)) {
                        $wasabi->delete($path);
                        Log::debug("🗑️ Archivo Wasabi eliminado: {$path}");
                    }
                } else {
                    // Archivo local
                    if (Storage::disk('local')->exists($path)) {
                        Storage::disk('local')->delete($path);
                        Log::debug("🗑️ Archivo local eliminado: {$path}");
                    }
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ Error eliminando archivo {$path}: " . $e->getMessage());
            }
        }
    }

    /**
     * 🆕 NUEVO: Verificar si una ruta es de Wasabi (detecta por patrón)
     */
    private function isWasabiPath(string $path): bool
    {
        // ✅ Detectar por patrón de ruta en lugar de campo storage_type
        return str_starts_with($path, 'reports/') ||
            str_starts_with($path, 'downloads/') ||
            str_contains($path, 'wasabi') ||
            (!str_starts_with($path, '/') && !str_starts_with($path, storage_path()));
    }

    /**
     * 🆕 NUEVO: Obtener tamaño de archivo (local o Wasabi)
     */
    private function getFileSize(string $path): int
    {
        try {
            if ($this->isWasabiPath($path)) {
                $wasabi = Storage::disk('wasabi');
                return $wasabi->exists($path) ? $wasabi->size($path) : 0;
            } else {
                return Storage::disk('local')->exists($path) ? Storage::disk('local')->size($path) : 0;
            }
        } catch (\Exception $e) {
            Log::warning("No se pudo obtener tamaño de {$path}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * 🆕 NUEVO: Descargar archivo desde Wasabi temporalmente
     */
    public function downloadFromWasabi(string $wasabiPath): ?string
    {
        if (!$this->isWasabiPath($wasabiPath)) {
            return null;
        }

        $wasabi = Storage::disk('wasabi');
        if (!$wasabi->exists($wasabiPath)) {
            return null;
        }

        // ✅ Crear archivo temporal
        $tempPath = storage_path('app/tmp/' . basename($wasabiPath));

        // Asegurar que existe el directorio
        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        try {
            // ✅ Descargar usando stream para archivos grandes
            $stream = $wasabi->readStream($wasabiPath);
            if (!$stream) {
                throw new \Exception("No se pudo abrir stream de Wasabi");
            }

            $local = fopen($tempPath, 'w+b');
            if (!$local) {
                throw new \Exception("No se pudo crear archivo temporal");
            }

            stream_copy_to_stream($stream, $local);
            fclose($stream);
            fclose($local);

            return $tempPath;

        } catch (\Exception $e) {
            Log::error("Error descargando de Wasabi: " . $e->getMessage());
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            return null;
        }
    }

    /**
     * 🆕 NUEVO: Detectar automáticamente el tipo de storage de todos los archivos
     */
    public function getStorageType(): string
    {
        if (!$this->file_path) return 'none';

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];

        $hasWasabi = false;
        $hasLocal = false;

        foreach ($paths as $path) {
            if ($this->isWasabiPath($path)) {
                $hasWasabi = true;
            } else {
                $hasLocal = true;
            }
        }

        if ($hasWasabi && $hasLocal) {
            return 'mixed'; // Algunos en Wasabi, otros local
        } elseif ($hasWasabi) {
            return 'wasabi';
        } elseif ($hasLocal) {
            return 'local';
        }

        return 'unknown';
    }

    /**
     * ✅ Cleanup automático al eliminar
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($reportGeneration) {
            $reportGeneration->deleteFiles();
        });
    }

    /**
     * 🆕 NUEVO: Scope para reportes expirados
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now())
            ->orWhere('created_at', '<', now()->subDays(30)); // Fallback
    }

    /**
     * 🆕 NUEVO: Scope para reportes que se pueden mover a Wasabi
     */
    public function scopeCanMoveToWasabi($query)
    {
        return $query->where('status', 'completed')
            ->whereNotNull('file_path')
            // Solo reportes de los últimos 14 días (no mover archivos muy antiguos)
            ->where('created_at', '>', now()->subDays(14));
    }
}
