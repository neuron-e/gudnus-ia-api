<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ReportGeneration extends Model
{
    protected $fillable = [
        'project_id',
        'status', // processing, completed, failed
        'user_email',
        'total_images',
        'processed_images',
        'file_path', // o array de paths si son múltiples PDFs
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
        return $this->status === 'completed' && $this->file_path;
    }

    /**
     * ✅ Obtener URL(s) de descarga
     */
    public function getDownloadUrls(): array
    {
        if (!$this->isReady()) return [];

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];

        return collect($paths)->map(function ($path) {
            return route('reports.download', ['id' => $this->id, 'file' => basename($path)]);
        })->toArray();
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
     * ✅ Cleanup de archivos al eliminar
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($reportGeneration) {
            $reportGeneration->deleteFiles();
        });
    }

    /**
     * ✅ Eliminar archivos asociados
     */
    public function deleteFiles(): void
    {
        if (!$this->file_path) return;

        $paths = is_array($this->file_path) ? $this->file_path : [$this->file_path];

        foreach ($paths as $path) {
            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
