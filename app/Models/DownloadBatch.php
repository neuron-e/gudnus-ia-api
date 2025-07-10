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

        foreach ($this->file_paths as $path) {
            if (!file_exists($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ✅ Obtener tamaño total de archivos
     */
    public function getTotalSize(): int
    {
        if (!$this->file_paths) return 0;

        $totalSize = 0;
        foreach ($this->file_paths as $path) {
            if (file_exists($path)) {
                $totalSize += filesize($path);
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

        foreach ($this->file_paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
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
