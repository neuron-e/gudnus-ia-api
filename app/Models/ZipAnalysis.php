<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ZipAnalysis extends Model
{
    // ✅ Configuración para ID string
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'project_id',
        'original_filename',
        'file_size',
        'file_path', // Ruta del ZIP en storage
        'status', // uploaded, processing, completed, failed
        'progress', // 0-100
        'total_files', // Total de archivos en ZIP
        'valid_images', // Imágenes válidas encontradas
        'images_data', // JSON con datos de imágenes
        'error_message'
    ];

    protected $casts = [
        'images_data' => 'array',
        'file_size' => 'integer',
        'progress' => 'integer',
        'total_files' => 'integer',
        'valid_images' => 'integer'
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * ✅ Verificar si el análisis está completado y disponible
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed' &&
            $this->images_data &&
            !$this->hasExpired();
    }

    /**
     * ✅ Verificar si ha expirado (24 horas)
     */
    public function hasExpired(): bool
    {
        return $this->created_at->addHours(24)->isPast();
    }

    /**
     * ✅ Obtener imágenes válidas del análisis
     */
    public function getValidImages(): array
    {
        if (!$this->images_data) {
            return [];
        }

        return $this->images_data;
    }

    /**
     * ✅ Generar ruta de extracción temporal
     */
    public function getExtractedPath(): string
    {
        return storage_path("app/temp_extract_{$this->id}");
    }

    /**
     * ✅ Limpiar archivos al eliminar
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($analysis) {
            $analysis->cleanup();
        });
    }

    /**
     * ✅ Limpiar archivos físicos
     */
    public function cleanup(): void
    {
        // Eliminar ZIP original
        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        // Eliminar archivos extraídos
        $extractPath = $this->getExtractedPath();
        if (is_dir($extractPath)) {
            $this->removeDirectory($extractPath);
        }
    }

    /**
     * ✅ Eliminar directorio recursivamente
     */
    private function removeDirectory($dir): void
    {
        if (!is_dir($dir)) return;

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * ✅ Scopes útiles
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeNotExpired($query)
    {
        return $query->where('created_at', '>', now()->subHours(24));
    }

    public function scopeAvailable($query)
    {
        return $query->completed()->notExpired();
    }
}
