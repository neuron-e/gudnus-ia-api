<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
