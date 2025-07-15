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
        // ✅ Si no hay archivos, retornar false
        if (!$this->file_paths || empty($this->file_paths)) {
            return false;
        }

        try {
            $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');

            foreach ($this->file_paths as $path) {
                // ✅ Verificar archivos de Wasabi (empiezan con "downloads/")
                if (str_starts_with($path, 'downloads/')) {
                    if (!$wasabi->exists($path)) {
                        return false;
                    }
                }
                // ✅ Verificar archivos locales
                else {
                    if (!file_exists($path)) {
                        return false;
                    }
                }
            }

            // ✅ Si llegamos aquí, todos los archivos existen
            return true;

        } catch (\Exception $e) {
            // ✅ En caso de excepción, log el error y retornar false
            \Illuminate\Support\Facades\Log::error("Error verificando archivos en DownloadBatch {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     *



    /**
     * ✅ Obtener tamaño total de archivos
     */
    public function getTotalSize(): int
    {
        // ✅ Si no hay archivos, retornar 0
        if (!$this->file_paths || empty($this->file_paths)) {
            return 0;
        }

        try {
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
                    continue;
                }
            }

            return $totalSize;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error calculando tamaño total en DownloadBatch {$this->id}: " . $e->getMessage());
            return 0;
        }
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
        if (!$this->file_paths || empty($this->file_paths)) {
            return;
        }

        try {
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
                    \Illuminate\Support\Facades\Log::error("Error eliminando archivo individual: {$path} - " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error general eliminando archivos en DownloadBatch {$this->id}: " . $e->getMessage());
        }
    }

    /**
    /**
     * ✅ NUEVO: Verificar si archivos están en Wasabi
     */
    public function isStoredInWasabi(): bool
    {
        if (!$this->file_paths || empty($this->file_paths)) {
            return false;
        }

        try {
            return collect($this->file_paths)->every(function($path) {
                return str_starts_with($path, 'downloads/');
            });
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error verificando storage type en DownloadBatch {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ CORREGIDO: Obtener información detallada de archivos
     * ASEGURAR que SIEMPRE devuelve un array
     */
    public function getFilesInfo(): array
    {
        if (!$this->file_paths || empty($this->file_paths)) {
            return [];
        }

        try {
            $wasabi = \Illuminate\Support\Facades\Storage::disk('wasabi');
            $filesInfo = [];

            foreach ($this->file_paths as $path) {
                $info = [
                    'path' => $path,
                    'filename' => basename($path),
                    'exists' => false,
                    'size' => 0,
                    'size_formatted' => '0 B',
                    'storage_type' => str_starts_with($path, 'downloads/') ? 'wasabi' : 'local',
                    'error' => null
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
                    \Illuminate\Support\Facades\Log::warning("Error obteniendo info de archivo: {$path} - " . $e->getMessage());
                }

                $filesInfo[] = $info;
            }

            return $filesInfo;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error obteniendo información de archivos en DownloadBatch {$this->id}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * ✅ CORREGIDO: Helper para formatear tamaño de archivo
     * ASEGURAR que SIEMPRE devuelve un string
     */
    private function formatFileSize($bytes): string
    {
        try {
            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= pow(1024, $pow);
            return round($bytes, 2) . ' ' . $units[$pow];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Error formateando tamaño de archivo: " . $e->getMessage());
            return '0 B';
        }
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
