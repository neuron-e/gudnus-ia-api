<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DownloadBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type', // original, processed, analyzed, all
        'status', // pending, processing, completed, failed, cancelled
        'total_images',
        'processed_images',
        'file_paths', // JSON array
        'started_at',
        'completed_at',
        'expires_at',
        'error'
    ];

    protected $casts = [
        'file_paths' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * ✅ Verificar si el batch está listo para descarga
     */
    public function isReady(): bool
    {
        return $this->status === 'completed'
            && $this->file_paths
            && !$this->hasExpired()
            && $this->allFilesExist();
    }

    /**
     * ✅ Verificar si ha expirado
     */
    public function hasExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * ✅ Calcular progreso en porcentaje
     */
    public function getProgressPercentage(): int
    {
        if ($this->total_images <= 0) return 0;

        $progress = min(100, round(($this->processed_images / $this->total_images) * 100));

        // Si está completado, asegurar que sea 100%
        if ($this->status === 'completed') {
            return 100;
        }

        return $progress;
    }

    /**
     * ✅ Verificar que todos los archivos existen físicamente
     */
    public function allFilesExist(): bool
    {
        if (!$this->file_paths) return false;

        $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');

        foreach ($this->file_paths as $path) {
            // ✅ Verificar archivos de Wasabi (empiezan con "downloads/")
            if (str_starts_with($path, 'downloads/')) {
                if (!$wasabi->exists($path)) {
                    return false;
                }
            } // ✅ Verificar archivos locales
            else {
                if (!file_exists($path)) {
                    return false;
                }
            }
        }
    }



        /**
     * ✅ Obtener tamaño total de archivos
     */
    public function getTotalSize(): int
    {
        if (!$this->file_paths) return 0;

        $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');
        $totalSize = 0;

        foreach ($this->file_paths as $path) {
            try {
                // ✅ Archivos en Wasabi
                if (str_starts_with($path, 'downloads/')) {
                    if ($wasabi->exists($path)) {
                        $totalSize += $wasabi->size($path);
                    }
                }
                // ✅ Archivos locales
                else {
                    if (file_exists($path)) {
                        $totalSize += filesize($path);
                    }
                }
            } catch (\Exception $e) {
                // Log pero continuar con otros archivos
                \Illuminate\Support\Facades\Log::warning("Error obteniendo tamaño de archivo: {$path} - " . $e->getMessage());
            }
        }

        return $totalSize;
    }


    /**
     * ✅ Formatear tamaño total
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->getTotalSize();
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * ✅ Limpiar archivos al eliminar
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($batch) {
            $batch->deleteFiles();
        });
    }

    /**
     * ✅ Eliminar archivos físicos
     */
    public function deleteFiles(): void
    {
        if (!$this->file_paths) return;

        $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');

        foreach ($this->file_paths as $path) {
            try {
                // ✅ Eliminar archivos de Wasabi
                if (str_starts_with($path, 'downloads/')) {
                    if ($wasabi->exists($path)) {
                        $wasabi->delete($path);
                        \Illuminate\Support\Facades\Log::info("🗑️ Archivo Wasabi eliminado: {$path}");
                    }
                }
                // ✅ Eliminar archivos locales
                else {
                    if (file_exists($path)) {
                        @unlink($path);
                        \Illuminate\Support\Facades\Log::info("🗑️ Archivo local eliminado: {$path}");
                    }
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Error eliminando archivo: {$path} - " . $e->getMessage());
            }
        }
    }

    public function getFilesInfo(): array
    {
        if (!$this->file_paths) return [];

        $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');
        $filesInfo = [];

        foreach ($this->file_paths as $path) {
            $info = [
                'path' => $path,
                'filename' => basename($path),
                'exists' => false,
                'size' => 0,
                'size_formatted' => '0 B',
                'storage_type' => str_starts_with($path, 'downloads/') ? 'wasabi' : 'local'
            ];

            try {
                if (str_starts_with($path, 'downloads/')) {
                    // Archivo Wasabi
                    if ($wasabi->exists($path)) {
                        $info['exists'] = true;
                        $info['size'] = $wasabi->size($path);
                        $info['size_formatted'] = $this->formatFileSize($info['size']);
                    }
                } else {
                    // Archivo local
                    if (file_exists($path)) {
                        $info['exists'] = true;
                        $info['size'] = filesize($path);
                        $info['size_formatted'] = $this->formatFileSize($info['size']);
                    }
                }
            } catch (\Exception $e) {
                $info['error'] = $e->getMessage();
            }

            $filesInfo[] = $info;
        }

        return $filesInfo;
    }

    /**
     * ✅ HELPER: Formatear tamaño de archivo
     */
    private function formatFileSize($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * ✅ NUEVO: Verificar si archivos están en Wasabi
     */
    public function isStoredInWasabi(): bool
    {
        if (!$this->file_paths) return false;

        return collect($this->file_paths)->every(function($path) {
            return str_starts_with($path, 'downloads/');
        });
    }


    /**
     * ✅ Scopes útiles
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'processing']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    public function scopeReady($query)
    {
        return $query->where('status', 'completed')
            ->where(function($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
