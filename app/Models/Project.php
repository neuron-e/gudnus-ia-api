<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
        'panel_brand',
        'panel_model',
        'installation_name',
        'inspector_name',
        'cell_count',
        'column_count',
        'description',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ RELACIONES

    /**
     * 👤 Usuario propietario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 📁 Carpetas del proyecto
     */
    public function folders()
    {
        return $this->hasMany(Folder::class);
    }

    public function children()
    {
        return $this->hasMany(Folder::class);
    }

    /**
     * 🖼️ NUEVA: Relación directa con imágenes
     */
    public function images()
    {
        return $this->hasMany(Image::class);
    }

    /**
     * 🎭 Batches unificados
     */
    public function unifiedBatches()
    {
        return $this->hasMany(UnifiedBatch::class);
    }

    /**
     * 📊 Resultados de análisis de imágenes
     */
    public function imageAnalysisResults()
    {
        return $this->hasManyThrough(ImageAnalysisResult::class, Image::class);
    }

    /**
     * 🖼️ Imágenes procesadas
     */
    public function processedImages()
    {
        return $this->hasManyThrough(ProcessedImage::class, Image::class);
    }

    protected $appends = ['created_by'];
    public function getCreatedByAttribute() {
        return $this->user->name;
    }

    // ✅ SCOPES ÚTILES

    /**
     * 📊 Proyectos con actividad reciente
     */
    public function scopeWithRecentActivity($query, int $days = 7)
    {
        return $query->where('updated_at', '>=', now()->subDays($days));
    }

    /**
     * 🔍 Proyectos por usuario
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * 📊 Proyectos con imágenes
     */
    public function scopeWithImages($query)
    {
        return $query->whereHas('images');
    }

    /**
     * 🤖 Proyectos con análisis completados
     */
    public function scopeWithAnalyzedImages($query)
    {
        return $query->whereHas('images.processedImage', function($q) {
            $q->whereNotNull('ai_response_json');
        });
    }

    // ✅ MÉTODOS DE ESTADÍSTICAS

    /**
     * 📊 Obtener estadísticas completas del proyecto
     */
    public function getFullStats(): array
    {
        return [
            'project_info' => [
                'id' => $this->id,
                'name' => $this->name,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
            'structure' => [
                'total_folders' => $this->folders()->count(),
                'root_folders' => $this->folders()->whereNull('parent_id')->count(),
            ],
            'images' => Image::getProjectStats($this->id),
            'batches' => [
                'total' => $this->unifiedBatches()->count(),
                'active' => $this->unifiedBatches()->active()->count(),
                'completed' => $this->unifiedBatches()->whereIn('status', ['completed', 'completed_with_errors'])->count(),
                'failed' => $this->unifiedBatches()->where('status', 'failed')->count(),
            ],
            'processing_summary' => $this->getProcessingSummary(),
        ];
    }

    /**
     * 🔄 Resumen de procesamiento
     */
    public function getProcessingSummary(): array
    {
        $images = $this->images()->with(['processedImage', 'analysisResult'])->get();

        $summary = [
            'total_images' => $images->count(),
            'pending' => 0,
            'processed' => 0,
            'analyzed' => 0,
            'errors' => 0,
            'completion_percentage' => 0,
            'analysis_percentage' => 0,
        ];

        foreach ($images as $image) {
            switch ($image->processing_status) {
                case 'pending':
                    $summary['pending']++;
                    break;
                case 'processed':
                    $summary['processed']++;
                    break;
                case 'analyzed':
                    $summary['analyzed']++;
                    break;
                case 'error':
                    $summary['errors']++;
                    break;
            }
        }

        if ($summary['total_images'] > 0) {
            $summary['completion_percentage'] = round(
                (($summary['processed'] + $summary['analyzed']) / $summary['total_images']) * 100,
                2
            );

            $summary['analysis_percentage'] = round(
                ($summary['analyzed'] / $summary['total_images']) * 100,
                2
            );
        }

        return $summary;
    }

    /**
     * 🏥 Estado de salud del proyecto
     */
    public function getHealthStatus(): array
    {
        $stats = $this->getFullStats();

        $health = [
            'overall_status' => 'healthy',
            'issues' => [],
            'recommendations' => [],
        ];

        // ✅ Verificar si hay imágenes
        if ($stats['images']['total'] === 0) {
            $health['overall_status'] = 'warning';
            $health['issues'][] = 'No hay imágenes en el proyecto';
            $health['recommendations'][] = 'Subir imágenes para comenzar el procesamiento';
        }

        // ✅ Verificar batches colgados
        $stuckBatches = $this->unifiedBatches()->stuck()->count();
        if ($stuckBatches > 0) {
            $health['overall_status'] = 'warning';
            $health['issues'][] = "{$stuckBatches} batches parecen estar colgados";
            $health['recommendations'][] = 'Revisar batches activos y considerar cancelación o restart';
        }

        // ✅ Verificar tasa de errores alta
        $errorRate = $stats['images']['total'] > 0
            ? ($stats['images']['errors'] / $stats['images']['total']) * 100
            : 0;

        if ($errorRate > 20) {
            $health['overall_status'] = 'critical';
            $health['issues'][] = "Tasa de errores alta: {$errorRate}%";
            $health['recommendations'][] = 'Revisar calidad de las imágenes y configuración del sistema';
        }

        // ✅ Verificar estructura de carpetas
        if ($stats['structure']['total_folders'] === 0) {
            $health['overall_status'] = 'warning';
            $health['issues'][] = 'No hay estructura de carpetas definida';
            $health['recommendations'][] = 'Crear estructura de carpetas antes de subir imágenes';
        }

        return $health;
    }

    // ✅ MÉTODOS DE UTILIDAD

    /**
     * 🧹 Limpiar proyecto completamente
     */
    public function cleanupCompletely(): array
    {
        $results = [
            'deleted_batches' => 0,
            'deleted_images' => 0,
            'deleted_folders' => 0,
            'errors' => []
        ];

        try {
            \DB::transaction(function() use (&$results) {
                // 1. Cancelar batches activos
                $activeBatches = $this->unifiedBatches()->active()->get();
                foreach ($activeBatches as $batch) {
                    $batch->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => 'project_cleanup'
                    ]);
                }

                // 2. Eliminar todos los batches
                $results['deleted_batches'] = $this->unifiedBatches()->delete();

                // 3. Eliminar imágenes y sus relaciones
                foreach ($this->images as $image) {
                    if ($image->processedImage) {
                        $image->processedImage->delete();
                    }
                    if ($image->analysisResult) {
                        $image->analysisResult->delete();
                    }
                }
                $results['deleted_images'] = $this->images()->delete();

                // 4. Eliminar estructura de carpetas
                $results['deleted_folders'] = $this->folders()->delete();
            });

        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * 📁 Obtener path base en storage
     */
    public function getStorageBasePath(): string
    {
        return "projects/{$this->id}";
    }

    /**
     * 🔍 Buscar imágenes con criterios
     */
    public function searchImages(array $criteria): \Illuminate\Database\Eloquent\Builder
    {
        return Image::advancedSearch(array_merge($criteria, ['project_id' => $this->id]));
    }
}
