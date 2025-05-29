<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Folder extends Model
{
    use HasFactory;
    protected $fillable = [
        'project_id',
        'parent_id',
        'name',
        'type',
    ];

    protected $appends = ['status', 'has_processed_images', 'has_unprocessed_images', 'has_error_images'];

    public static function booted()
    {
        static::saving(function ($folder) {
            $folder->full_path = $folder->generateFullPath();
        });
    }

    /**
     * Estado básico de la carpeta actual (solo considera imágenes directas)
     */
    public function getStatusAttribute()
    {
        $images = $this->images;

        if ($images->isEmpty()) {
            // Sin imágenes directas, pero comprobamos si los hijos tienen imágenes
            if ($this->hasImagesInChildren()) {
                // Si algún hijo tiene imágenes, delegamos el estado a los hijos
                if ($this->hasErrorImagesInChildren()) {
                    return 'error'; // Al menos un hijo tiene imágenes con error
                } elseif ($this->hasUnprocessedImagesInChildren()) {
                    return 'unprocessed'; // Al menos un hijo tiene imágenes sin procesar
                } else {
                    return 'processed'; // Todos los hijos tienen imágenes procesadas
                }
            }
            return 'empty'; // Sin imágenes en esta carpeta ni en los hijos
        }

        if ($images->some(fn ($img) => $img->status === 'error')) {
            return 'error';
        }

        // Verificamos si hay imágenes con errores
        if ($images->some(fn ($img) => $img->analysisResult && $img->analysisResult->integrity_score == 0)) {
            return 'error'; // Al menos una imagen tiene un puntaje de integridad bajo
        }

        // Verificamos el estado de procesamiento
        if ($images->every(fn ($img) =>
            $img->processedImage && $img->processedImage->ai_response_json !== null
        )) {
            return 'processed'; // Todas las imágenes tienen análisis IA
        }

        return 'unprocessed'; // Algunas imágenes no están procesadas
    }

    /**
     * Indica si esta carpeta o alguno de sus hijos tiene imágenes procesadas
     */
    public function getHasProcessedImagesAttribute()
    {
        // Verificar imágenes directas
        if ($this->images->some(fn ($img) =>
            $img->processedImage && $img->processedImage->ai_response_json !== null
        )) {
            return true;
        }

        // Verificar recursivamente en los hijos
        foreach ($this->children as $child) {
            if ($child->has_processed_images) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si esta carpeta o alguno de sus hijos tiene imágenes sin procesar
     */
    public function getHasUnprocessedImagesAttribute()
    {
        // Verificar imágenes directas
        if ($this->images->some(fn ($img) =>
            !$img->processedImage || $img->processedImage->ai_response_json === null
        )) {
            return true;
        }

        // Verificar recursivamente en los hijos
        foreach ($this->children as $child) {
            if ($child->has_unprocessed_images) {
                return true;
            }
        }

        return false;
    }

    public function generateFullPath()
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            $path[] = $parent->name;
            $parent = $parent->parent;
        }

        // Añadir nombre del proyecto como raíz
        if ($this->project && $this->project->name) {
            $path[] = $this->project->name;
        }

        return implode(' / ', array_reverse($path));
    }


    /**
     * Indica si esta carpeta o alguno de sus hijos tiene imágenes con errores
     */
    public function getHasErrorImagesAttribute()
    {

        if ($this->images->some(fn ($img) => $img->status === 'error')) {
            return true;
        }

        // Verificar imágenes directas con errores
        if ($this->images->some(fn ($img) => $img->analysisResult && $img->analysisResult->integrity_score == 0)) {
            return true;
        }

        // Verificar recursivamente en los hijos
        foreach ($this->children as $child) {
            if ($child->has_error_images) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si alguna carpeta hija tiene imágenes
     */
    private function hasImagesInChildren()
    {
        foreach ($this->children as $child) {
            if ($child->images->isNotEmpty() || $child->hasImagesInChildren()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica si alguna carpeta hija tiene imágenes sin procesar
     */
    private function hasUnprocessedImagesInChildren()
    {
        foreach ($this->children as $child) {
            if ($child->has_unprocessed_images) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica si alguna carpeta hija tiene imágenes con errores
     */
    private function hasErrorImagesInChildren()
    {
        foreach ($this->children as $child) {
            if ($child->has_error_images) {
                return true;
            }
        }
        return false;
    }


    public function storeImage(string $binary, string $originalName): Image
    {
        $filename = uniqid() . '_' . Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . pathinfo($originalName, PATHINFO_EXTENSION);
        $path = "projects/{$this->project_id}/images/" . $filename;

        Storage::disk('wasabi')->put($path, $binary);

        return Image::create([
            'folder_id' => $this->id,
            'project_id' => $this->project_id,
            'filename' => $filename,
            'original_path' => $path,
        ]);
    }


    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function parent()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Folder::class, 'parent_id');
    }

    public function images()
    {
        return $this->hasMany(Image::class);
    }
}
