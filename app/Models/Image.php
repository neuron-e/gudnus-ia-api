<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'folder_id',
        'project_id',        // ✅ EXISTE: Relación directa con proyecto
        'original_path',     // ✅ EXISTE: Path de la imagen original
        'status',           // ✅ EXISTE: Estado del procesamiento
        'is_processed',     // ✅ EXISTE: Flag de procesado
        'processed_at',     // ✅ EXISTE: Timestamp de procesamiento
        'is_counted'        // ✅ EXISTE: Flag de contado
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'is_counted' => 'boolean',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ✅ ACCESSORS PARA COMPATIBILIDAD

    protected $appends = ['created_by'];
    public function getCreatedByAttribute() {
        return $this->user?->name;
    }

    /**
     * 📄 Obtener filename desde original_path
     */
    public function getFilenameAttribute(): ?string
    {
        return $this->original_path ? basename($this->original_path) : null;
    }

    /**
     * ✅ Verificar si está procesada (usando campo existente)
     */
    public function getIsProcessedAttribute(): bool
    {
        return (bool) $this->attributes['is_processed'] ?? false;
    }

    // ✅ RELACIONES

    /**
     * 📁 Relación con carpeta (mantiene compatibilidad)
     */
    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * 🏗️ NUEVA: Relación directa con proyecto
     */
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * 🖼️ Imagen procesada (1:1)
     */
    public function processedImage()
    {
        return $this->hasOne(ProcessedImage::class);
    }

    /**
     * 📊 Resultado de análisis (1:1)
     */
    public function analysisResult()
    {
        return $this->hasOne(ImageAnalysisResult::class);
    }

    // ✅ SCOPES ÚTILES

    /**
     * 🏗️ Filtrar por proyecto
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * ✅ Imágenes procesadas (usando flag existente)
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * 🤖 Imágenes analizadas (verificar si existe relación)
     */
    public function scopeAnalyzed($query)
    {
        // ✅ Intentar con relación si existe, sino usar flag
        if (method_exists($this, 'analysisResult')) {
            return $query->whereHas('analysisResult');
        } else {
            // Fallback: usar processed + timestamp reciente
            return $query->where('is_processed', true)
                ->whereNotNull('processed_at')
                ->where('processed_at', '>=', now()->subDays(30));
        }
    }

    /**
     * 📊 Por estado
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 📁 Por carpeta específica
     */
    public function scopeInFolder($query, int $folderId)
    {
        return $query->where('folder_id', $folderId);
    }

    // ✅ MÉTODOS DE ESTADO ADAPTADOS

    /**
     * 🤖 Verificar si está analizada (adaptado a estructura actual)
     */
    public function getIsAnalyzedAttribute(): bool
    {
        // ✅ Si existe relación analysisResult, usarla
        if ($this->relationLoaded('analysisResult') && $this->analysisResult) {
            return true;
        }

        // ✅ Si existe relación processedImage, verificar ai_response_json
        if ($this->relationLoaded('processedImage') && $this->processedImage && $this->processedImage->ai_response_json) {
            return true;
        }

        // ✅ Fallback: usar lógica de procesado + tiempo reciente
        return $this->is_processed &&
            $this->processed_at &&
            $this->processed_at->diffInDays(now()) <= 30;
    }

    /**
     * 📊 Obtener progreso de procesamiento (adaptado)
     */
    public function getProcessingStatusAttribute(): string
    {
        if ($this->status === 'error') {
            return 'error';
        } elseif ($this->status === 'processing') {
            return 'processing';
        } elseif ($this->is_analyzed) {
            return 'analyzed';
        } elseif ($this->is_processed) {
            return 'processed';
        } else {
            return 'pending';
        }
    }

    // ✅ MÉTODOS DE UTILIDAD

    /**
     * 📁 Obtener path completo en estructura organizada
     */
    public function getOrganizedPath(string $type = 'original'): string
    {
        return "projects/{$this->project_id}/{$type}/folder_{$this->folder_id}/{$this->filename}";
    }

    /**
     * 📊 Obtener metadata básica (adaptado a estructura actual)
     */
    public function getMetadata(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename, // ✅ Usa accessor
            'project_id' => $this->project_id,
            'folder_id' => $this->folder_id,
            'folder_name' => $this->folder?->name,
            'original_path' => $this->original_path,
            'status' => $this->status,
            'processing_status' => $this->processing_status,
            'is_processed' => $this->is_processed,
            'is_analyzed' => $this->is_analyzed,
            'processed_at' => $this->processed_at,
            'is_counted' => $this->is_counted ?? false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    // ✅ EVENTOS DEL MODELO

    /**
     * ✅ AUTO-ASSIGN project_id cuando se crea una imagen (adaptado)
     */
    protected static function boot()
    {
        parent::boot();

        // ✅ AUTO-ASSIGN project_id cuando se crea una imagen
        static::creating(function ($image) {
            if (!$image->project_id && $image->folder_id) {
                $folder = Folder::find($image->folder_id);
                if ($folder && $folder->project_id) {
                    $image->project_id = $folder->project_id;
                }
            }
        });

        // ✅ VERIFICAR consistencia al actualizar folder_id
        static::updating(function ($image) {
            if ($image->isDirty('folder_id') && $image->folder_id) {
                $folder = Folder::find($image->folder_id);
                if ($folder && $folder->project_id !== $image->project_id) {
                    // Actualizar project_id para mantener consistencia
                    $image->project_id = $folder->project_id;
                }
            }
        });
    }

    // ✅ MÉTODOS ESTÁTICOS ÚTILES

    /**
     * 📊 Obtener estadísticas de imágenes por proyecto
     */
    public static function getProjectStats(int $projectId): array
    {
        $query = self::where('project_id', $projectId);

        return [
            'total' => $query->count(),
            'pending' => $query->where('status', 'pending')->count(),
            'processing' => $query->where('status', 'processing')->count(),
            'processed' => $query->processed()->count(),
            'analyzed' => $query->analyzed()->count(),
            'errors' => $query->where('status', 'error')->count(),
        ];
    }

    /**
     * 🔍 Buscar imágenes por criterios avanzados
     */
    public static function advancedSearch(array $criteria): \Illuminate\Database\Eloquent\Builder
    {
        $query = self::query();

        if (isset($criteria['project_id'])) {
            $query->where('project_id', $criteria['project_id']);
        }

        if (isset($criteria['folder_ids'])) {
            $query->whereIn('folder_id', $criteria['folder_ids']);
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['processing_status'])) {
            match($criteria['processing_status']) {
                'processed' => $query->processed(),
                'analyzed' => $query->analyzed(),
                'pending' => $query->where('status', 'pending'),
                'error' => $query->where('status', 'error')
            };
        }

        if (isset($criteria['filename_contains'])) {
            $query->where('filename', 'like', '%' . $criteria['filename_contains'] . '%');
        }

        if (isset($criteria['created_after'])) {
            $query->where('created_at', '>=', $criteria['created_after']);
        }

        if (isset($criteria['created_before'])) {
            $query->where('created_at', '<=', $criteria['created_before']);
        }

        return $query;
    }
}
