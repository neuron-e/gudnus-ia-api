<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\Font;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);
        $query = Project::query();

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Store a newly created project in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id',
            'panel_brand' => 'nullable|string|max:255',
            'panel_model' => 'nullable|string|max:255',
            'installation_name' => 'nullable|string|max:255',
            'inspector_name' => 'nullable|string|max:255',
            'cell_count' => 'nullable|integer',
            'column_count' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        return Project::create($validated);
    }

    /**
     * Display the specified project with its folder structure.
     */
    public function show(Project $project): Project
    {
        // Obtener solo las carpetas raíz (parent_id = null)
        $rootFolders = Folder::where('project_id', $project->id)
            ->whereNull('parent_id')
            ->with('images.processedImage', 'images.analysisResult')
            ->get();

        // Si hay carpetas, adjuntarlas al proyecto y cargar su jerarquía recursivamente
        if ($rootFolders->count() > 0) {
            $project->children = $rootFolders;

            // Cargar hijos recursivamente para cada carpeta raíz
            foreach ($rootFolders as $folder) {
                $this->loadChildrenRecursive($folder);
            }
        } else {
            $project->children = collect([]);
        }

        // Muy importante: SÓLO retornar el proyecto, no cualquier colección adicional
        return $project;
    }

    /**
     * Carga recursivamente los hijos de una carpeta
     */
    protected function loadChildrenRecursive($folder, $parentPath = '')
    {
        // Construir el path actual para esta carpeta
        $currentPath = trim($parentPath . ' / ' . $folder->name, ' /');
        $folder->full_path = $currentPath;

        // Asignar el path a las imágenes de esta carpeta
        foreach ($folder->images as $image) {
            $filename = basename($image->original_path);
            $image->filename = $filename;
            $image->full_path = $currentPath . ' / ' . $filename;
            $image->folder_path = $currentPath;
        }

        // Cargar hijos y relaciones
        $folder->load(['children' => function ($query) {
            $query->with('images.processedImage', 'images.analysisResult');
        }]);

        // Aplicar recursivamente a cada hijo
        foreach ($folder->children as $child) {
            $this->loadChildrenRecursive($child, $currentPath);
        }
    }


    /**
     * Update the specified project in storage.
     */
    public function update(Request $request, Project $project)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'panel_brand' => 'nullable|string|max:255',
            'panel_model' => 'nullable|string|max:255',
            'installation_name' => 'nullable|string|max:255',
            'inspector_name' => 'nullable|string|max:255',
            'cell_count' => 'nullable|integer',
            'column_count' => 'nullable|integer',
            'description' => 'nullable|string',
        ]);

        $project->update($validated);
        return $project;
    }


    public function destroy(Project $project, Request $request)
    {
        $force = $request->get('force', false);

        // 1. Obtener carpetas
        $folders = Folder::where('project_id', $project->id)->with('images')->get();
        $folderIds = $folders->pluck('id');

        // 2. Obtener todas las imágenes con relaciones
        $images = \App\Models\Image::whereIn('folder_id', $folderIds)
            ->with(['processedImage', 'analysisResult'])
            ->get();

        $folderCount = $folders->count();
        $imageCount = $images->count();
        $processed = $images->filter(fn($img) => $img->processedImage !== null);
        $analyzedCount = $processed->filter(fn($img) => $img->processedImage->ai_response_json !== null)->count();
        $processedCount = $processed->count();
        $unprocessedCount = $imageCount - $processedCount;

        if (!$force && ($folderCount > 0 || $imageCount > 0)) {
            return response()->json([
                'message' => 'Este proyecto contiene contenido que será eliminado:',
                'requires_confirmation' => true,
                'summary' => [
                    'folders' => $folderCount,
                    'images' => $imageCount,
                    'processed' => $processedCount,
                    'analyzed' => $analyzedCount,
                    'unprocessed' => $unprocessedCount,
                ]
            ], 403);
        }

        // 🔥 Eliminación forzada
        foreach ($folders as $folder) {
            foreach ($folder->images as $image) {
                $image->analysisResult()?->delete();
                $image->processedImage()?->delete();
                $image->delete();
            }
            $folder->delete();
        }

        $project->delete();

        return response()->json(['message' => 'Proyecto y todo su contenido eliminado']);
    }

    public function generateReport(Project $project)
    {
        // Cargar estructura completa del proyecto
        $rootFolders = Folder::where('project_id', $project->id)
            ->whereNull('parent_id')
            ->with('images.processedImage')
            ->get();

        $project->children = $rootFolders;
        foreach ($rootFolders as $folder) {
            $this->loadChildrenRecursive($folder);
        }

        // Recoger todas las imágenes procesadas
        $allImages = collect();
        $this->collectImagesRecursive($project->children, $allImages);

        // CRÍTICO: Solo imágenes que realmente tienen ProcessedImage
        $imagesWithProcessed = $allImages->filter(function ($img) {
            return $img->processedImage !== null;
        });

        // Generar imágenes analizadas para TODAS las imágenes (con y sin errores)
        $analyzedPaths = $imagesWithProcessed->map(function ($img, $index) {
            return $this->generateAnalyzedImageBase64($img->processedImage);
        })->filter();

        // Configurar DomPDF con opciones optimizadas
        $pdf = Pdf::loadView('pdf.project_report_calude', [
            'project' => $project,
            'images' => $imagesWithProcessed, // Usar todas las imágenes procesadas
            'analyzedPaths' => $analyzedPaths->values(),
        ]);

        // Configuraciones para mejor compatibilidad con DomPDF
        $pdf->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'Arial',
                'dpi' => 96,
                'defaultPaperSize' => 'a4',
                'fontHeightRatio' => 1.0,
                'isJavascriptEnabled' => false,
                'debugKeepTemp' => false,
                'debugCss' => false,
                'debugLayout' => false,
                'debugLayoutLines' => false,
                'debugLayoutBlocks' => false,
                'debugLayoutInline' => false,
                'debugLayoutPaddingBox' => false,
            ]);

        return $pdf->download("informe-electroluminiscencia-{$project->name}-" . now()->format('Y-m-d') . ".pdf");
    }

    private function collectImagesRecursive($folders, &$allImages)
    {
        foreach ($folders as $folder) {
            if ($folder->images) {
                foreach ($folder->images as $img) {
                    $allImages->push($img);
                }
            }
            if ($folder->children) {
                $this->collectImagesRecursive($folder->children, $allImages);
            }
        }
    }

    private function generateAnalyzedImageBase64(ProcessedImage $processed): ?string
    {
        $wasabi = Storage::disk('wasabi');
        if (!$wasabi->exists($processed->corrected_path)) return null;

        $imageData = $wasabi->get($processed->corrected_path);
        $manager = new ImageManager(new ImagickDriver());
        $image = $manager->read($imageData);

        // CRÍTICO: Usar exactamente la misma lógica de filtrado que en el template
        $json = $processed->error_edits_json ?: $processed->ai_response_json;
        $prob = $processed->min_probability ?? 0.5;
        $response = json_decode($json, true);

        if (!isset($response['predictions'])) return null;

        // Aplicar exactamente el mismo filtro que en el Blade template
        $filteredPredictions = array_filter($response['predictions'], function($prediction) use ($prob) {
            return !isset($prediction['probability']) || $prediction['probability'] >= $prob;
        });

        // Colores mejorados para mejor visibilidad en PDF
        $errorColors = [
            'Intensidad' => '#FFA500',      // Naranja
            'Fingers' => '#00BFFF',         // Azul cielo
            'Black Edges' => '#FF0000',     // Rojo
            'Microgrietas' => '#8A2BE2',    // Violeta
            'Defectos' => '#32CD32',        // Verde lima
            'Soldadura' => '#FF69B4',       // Rosa
            'Celdas dañadas' => '#FF0000',  // Rojo para celdas dañadas
        ];

        // Solo dibujar las predicciones filtradas
        foreach ($filteredPredictions as $prediction) {
            $box = $prediction['boundingBox'];
            $left = (int) ($box['left'] * $image->width());
            $top = (int) ($box['top'] * $image->height());
            $width = (int) ($box['width'] * $image->width());
            $height = (int) ($box['height'] * $image->height());

            $tag = $prediction['tagName'] ?? '';
            $color = $errorColors[$tag] ?? '#FFFFFF';
            $label = sprintf('%s (%.1f%%)', $tag, ($prediction['probability'] ?? 0) * 100);

            // Rectángulo con grosor mejorado para PDF
            $rectangle = new Rectangle($width, $height);
            $rectangle->setBackgroundColor('rgba(0,0,0,0.1)'); // Fondo semi-transparente mejorado
            $rectangle->setBorder($color, 3); // Grosor aumentado para mejor visibilidad
            $image->drawRectangle($left, $top, $rectangle);

            // Texto con fondo para mejor legibilidad
            try {
                $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
                $font->setColor('#FFFFFF');
                $font->setSize(16); // Tamaño aumentado

                // Agregar fondo semi-transparente al texto
                $textBg = new Rectangle(strlen($label) * 8, 20);
                $textBg->setBackgroundColor($color);
                $image->drawRectangle($left, max(0, $top - 25), $textBg);

                $image->text($label, $left + 2, max(10, $top - 8), $font);
            } catch (\Exception $e) {
                // Fallback si no encuentra la fuente
                $image->text($label, $left + 2, max(10, $top - 8), function($font) {
                    $font->color('#FFFFFF');
                    $font->size(14);
                });
            }
        }

        $encoded = (string) $image->toJpeg(85); // Calidad mejorada para PDF
        return 'data:image/jpeg;base64,' . base64_encode($encoded);
    }

    private function getBase64FromWasabi(string $path): ?string
    {
        $wasabi = Storage::disk('wasabi');
        if (!$wasabi->exists($path)) return null;

        $data = $wasabi->get($path);

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data) ?? 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }



    public function getProcessingStatus(Project $project)
    {
        $batch = ImageBatch::where('project_id', $project->id)
            ->latest('id')
            ->first();

        if (!$batch) {
            return response()->json(['processing' => false, 'progress' => 0]);
        }

        $progress = $batch->total > 0 ? round(($batch->processed / $batch->total) * 100) : 0;

        return response()->json([
            'processing' => $batch->status === 'processing',
            'progress' => $progress
        ]);
    }
}
