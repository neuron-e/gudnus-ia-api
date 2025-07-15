<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportJob;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use App\Models\Project;
use App\Models\ReportGeneration;
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


    public function generateReport(Project $project, Request $request)
    {
        $request->validate([
            'user_email' => 'nullable|email',
            'max_images_per_page' => 'nullable|integer|min:10|max:100',
            'include_analyzed_images' => 'nullable|boolean',
            'force_new' => 'nullable|boolean', // ✅ Nueva opción
        ]);

        // ✅ Verificar si ya hay una generación en proceso para este proyecto
        $existingGeneration = ReportGeneration::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        if ($existingGeneration) {
            return response()->json([
                'message' => 'Ya hay una generación de reporte en proceso para este proyecto',
                'generation_id' => $existingGeneration->id,
                'progress' => $existingGeneration->getProgressPercentage(),
            ], 409);
        }

        // ✅ Verificar si hay un reporte disponible y no se fuerza uno nuevo
        if (!$request->input('force_new', false)) {
            $latestCompleted = ReportGeneration::where('project_id', $project->id)
                ->where('status', 'completed')
                ->latest('completed_at')
                ->first();

            if ($latestCompleted && !$latestCompleted->hasExpired()) {
                return response()->json([
                    'message' => 'Ya existe un reporte disponible para este proyecto',
                    'status' => 'already_exists',
                    'existing_report' => [
                        'id' => $latestCompleted->id,
                        'created_at' => $latestCompleted->created_at,
                        'expires_at' => $latestCompleted->expires_at,
                        'download_url' => route('reports.download', ['id' => $latestCompleted->id]),
                    ],
                    'suggestion' => 'Use force_new=true para generar un nuevo reporte',
                ], 200);
            }
        }

        // ✅ Contar imágenes procesadas para estimar tiempo
        $imageCount = $this->countProcessedImages($project);

        if ($imageCount === 0) {
            return response()->json([
                'error' => 'No hay imágenes procesadas para generar el reporte',
            ], 400);
        }

        // ✅ Calcular chunk size dinámico
        $defaultChunkSize = match(true) {
            $imageCount > 2000 => 2000,  // ✅ Aumentar límites
            $imageCount > 1000 => 1500,
            $imageCount > 500 => 1000,
            default => 5000              // ✅ Sin límite para proyectos normales
        };

        Log::info("🚀 Iniciando generación asíncrona de reporte para proyecto {$project->id} con {$imageCount} imágenes");

        // ✅ Crear registro de seguimiento
        $reportGeneration = ReportGeneration::create([
            'project_id' => $project->id,
            'status' => 'processing',
            'user_email' => $request->input('user_email'),
            'total_images' => $imageCount,
        ]);

        // ✅ Establecer expiración automática
        $reportGeneration->setExpiration(7); // 7 días

        // ✅ Despachar job asíncrono
        $job = new GenerateReportJob(
            projectId: $project->id,
            userEmail: $request->input('user_email'), // ✅ CORREGIDO: era 'userEmail'
            maxImagesPerPage: $request->input('max_images_per_page', $defaultChunkSize), // ✅ Chunk dinámico
            includeAnalyzedImages: $request->input('include_analyzed_images', true)
        );

        dispatch($job)->onQueue('reports');

        return response()->json([
            'message' => 'Generación de reporte iniciada. Recibirás una notificación cuando esté listo.',
            'generation_id' => $reportGeneration->id,
            'estimated_time' => $this->estimateGenerationTime($imageCount),
            'total_images' => $imageCount,
            'chunk_size' => $defaultChunkSize, // ✅ Informar chunk size usado
        ]);
    }

    public function getReportDownloadUrls(Project $project, $generationId = null)
    {
        $query = ReportGeneration::where('project_id', $project->id);

        if ($generationId) {
            $generation = $query->findOrFail($generationId);
        } else {
            $generation = $query->where('status', 'completed')
                ->latest('completed_at')
                ->first();
        }

        if (!$generation) {
            return response()->json([
                'error' => 'No se encontró un reporte disponible',
            ], 404);
        }

        if (!$generation->isReady()) {
            return response()->json([
                'error' => 'El reporte no está listo o ha expirado',
                'status' => $generation->status,
                'expired' => $generation->hasExpired()
            ], 410);
        }

        // ✅ Generar URLs presignadas válidas por 6 horas
        $downloadUrls = $generation->getPresignedDownloadUrls(6);

        if (empty($downloadUrls)) {
            return response()->json([
                'error' => 'No hay archivos disponibles para descarga',
            ], 404);
        }

        return response()->json([
            'generation_id' => $generation->id,
            'project_id' => $project->id,
            'total_files' => count($downloadUrls),
            'total_size_mb' => array_sum(array_column($downloadUrls, 'size_mb')),
            'expires_at' => $downloadUrls[0]['expires_at'], // Todas expiran al mismo tiempo
            'files' => $downloadUrls,
            'instructions' => count($downloadUrls) > 1
                ? 'Descarga cada archivo usando su URL directa. Los enlaces son válidos por 6 horas.'
                : 'Descarga directa disponible. El enlace es válido por 6 horas.'
        ]);
    }

    /**
     * ✅ NUEVO: URL presignada para un archivo específico
     */
    public function getReportFileUrl(Project $project, $generationId, $fileName)
    {
        $generation = ReportGeneration::where('project_id', $project->id)
            ->findOrFail($generationId);

        if (!$generation->isReady()) {
            return response()->json([
                'error' => 'El reporte no está disponible',
                'status' => $generation->status
            ], 410);
        }

        $fileUrl = $generation->getSinglePresignedUrl($fileName, 6);

        if (!$fileUrl) {
            return response()->json([
                'error' => 'Archivo no encontrado: ' . $fileName
            ], 404);
        }

        return response()->json($fileUrl);
    }


    /**
     * ✅ NUEVO: Obtener estado de generación de reporte
     */
    public function getReportStatus(Project $project, $generationId = null)
    {
        $query = ReportGeneration::where('project_id', $project->id);

        if ($generationId) {
            $generation = $query->findOrFail($generationId);
        } else {
            $generation = $query->latest()->first();
        }

        if (!$generation) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No se encontró ninguna generación de reporte para este proyecto',
            ]);
        }

        $response = [
            'generation_id' => $generation->id,
            'status' => $generation->status,
            'progress' => $generation->getProgressPercentage(),
            'processed_images' => $generation->processed_images,
            'total_images' => $generation->total_images,
            'created_at' => $generation->created_at,
            'completed_at' => $generation->completed_at,
            'expires_at' => $generation->expires_at,
        ];

        if ($generation->status === 'completed') {
            $response['download_urls'] = $generation->getDownloadUrls();
            $response['is_expired'] = $generation->hasExpired();
        }

        if ($generation->status === 'failed') {
            $response['error_message'] = $generation->error_message;
        }

        return response()->json($response);
    }

    /**
     * ✅ NUEVO: Descargar reporte generado
     */
    public function downloadReport($generationId, $fileName = null)
    {
        $generation = ReportGeneration::findOrFail($generationId);

        if (!$generation->isReady()) {
            return response()->json([
                'error' => 'El reporte no está listo para descarga',
                'status' => $generation->status,
            ], 400);
        }

        if ($generation->hasExpired()) {
            return response()->json([
                'error' => 'El reporte ha expirado. Genera uno nuevo.',
            ], 410);
        }

        // ✅ Determinar qué archivo descargar
        $filePaths = is_array($generation->file_path) ? $generation->file_path : [$generation->file_path];

        if ($fileName) {
            // Buscar archivo específico
            $targetPath = collect($filePaths)->first(function ($path) use ($fileName) {
                return basename($path) === $fileName;
            });

            if (!$targetPath) {
                return response()->json(['error' => 'Archivo no encontrado'], 404);
            }

            $filePaths = [$targetPath];
        }

        // ✅ Si es un solo archivo, descarga directa
        if (count($filePaths) === 1) {
            return $this->downloadSingleReportFile($filePaths[0], $generation);
        }

        // ✅ Si son múltiples archivos, crear ZIP
        return $this->downloadMultipleReportFiles($filePaths, $generation);
    }

    /**
     * 🆕 NUEVO: Descargar un solo archivo de reporte (detecta automáticamente storage)
     */
    private function downloadSingleReportFile(string $filePath, ReportGeneration $generation)
    {
        // ✅ Detectar si es archivo de Wasabi por patrón de ruta
        $isWasabiFile = str_starts_with($filePath, 'reports/') ||
            str_starts_with($filePath, 'downloads/') ||
            (!str_starts_with($filePath, '/') && !str_starts_with($filePath, storage_path()));

        if ($isWasabiFile) {
            // ✅ Archivo en Wasabi - descargar temporalmente
            $tempPath = $generation->downloadFromWasabi($filePath);

            if (!$tempPath) {
                return response()->json(['error' => 'Archivo no encontrado en Wasabi'], 404);
            }

            return response()->download($tempPath)->deleteFileAfterSend(true);
        } else {
            // ✅ Archivo local
            if (!Storage::disk('local')->exists($filePath)) {
                return response()->json(['error' => 'Archivo no encontrado en el sistema'], 404);
            }

            return Storage::disk('local')->download($filePath);
        }
    }

    /**
     * 🆕 NUEVO: Descargar múltiples archivos de reporte como ZIP
     */
    private function downloadMultipleReportFiles(array $filePaths, ReportGeneration $generation)
    {
        $zipName = "informe-completo-{$generation->project->name}-" . now()->format('Y-m-d') . ".zip";
        $zipPath = storage_path("app/tmp/{$zipName}");

        // Asegurar que existe el directorio tmp
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el archivo ZIP'], 500);
        }

        foreach ($filePaths as $filePath) {
            $fileName = basename($filePath);

            // ✅ Detectar tipo de storage por patrón
            $isWasabiFile = str_starts_with($filePath, 'reports/') ||
                str_starts_with($filePath, 'downloads/') ||
                (!str_starts_with($filePath, '/') && !str_starts_with($filePath, storage_path()));

            if ($isWasabiFile) {
                // ✅ Archivo en Wasabi
                $wasabi = Storage::disk('wasabi');
                if ($wasabi->exists($filePath)) {
                    $zip->addFromString($fileName, $wasabi->get($filePath));
                }
            } else {
                // ✅ Archivo local
                if (Storage::disk('local')->exists($filePath)) {
                    $zip->addFile(
                        Storage::disk('local')->path($filePath),
                        $fileName
                    );
                }
            }
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * ✅ NUEVO: Listar reportes de un proyecto
     */
    /**
     * ✅ MEJORADO: Listar todos los reportes disponibles de un proyecto
     */
    public function listReports(Project $project)
    {
        $reports = ReportGeneration::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($generation) {
                $data = [
                    'id' => $generation->id,
                    'status' => $generation->status,
                    'progress' => $generation->getProgressPercentage(),
                    'total_images' => $generation->total_images,
                    'processed_images' => $generation->processed_images,
                    'created_at' => $generation->created_at,
                    'completed_at' => $generation->completed_at,
                    'expires_at' => $generation->expires_at,
                    'is_expired' => $generation->hasExpired(),
                    'user_email' => $generation->user_email,
                ];

                if ($generation->isReady()) {
                    // ✅ Incluir URLs presignadas directamente
                    $downloadUrls = $generation->getPresignedDownloadUrls(6);

                    $data['files'] = $downloadUrls;
                    $data['total_size_mb'] = array_sum(array_column($downloadUrls, 'size_mb'));
                    $data['file_count'] = count($downloadUrls);
                    $data['can_download'] = count($downloadUrls) > 0;
                    $data['direct_download'] = true; // ✅ Indica que son URLs directas

                    if (!empty($downloadUrls)) {
                        $data['primary_download_url'] = $downloadUrls[0]['download_url'];
                    }
                } else {
                    $data['files'] = [];
                    $data['can_download'] = false;
                    $data['direct_download'] = false;
                }

                return $data;
            });

        return response()->json([
            'reports' => $reports,
            'total_reports' => $reports->count(),
            'available_reports' => $reports->where('can_download', true)->count(),
            'processing_reports' => $reports->where('status', 'processing')->count(),
        ]);
    }


    /**
     * ✅ NUEVO: Cancelar generación en proceso
     */
    public function cancelReportGeneration($generationId)
    {
        $generation = ReportGeneration::findOrFail($generationId);

        if ($generation->status !== 'processing') {
            return response()->json([
                'error' => 'Solo se pueden cancelar generaciones en proceso',
            ], 400);
        }

        $generation->update([
            'status' => 'failed',
            'error_message' => 'Cancelado por el usuario',
        ]);

        return response()->json([
            'message' => 'Generación cancelada correctamente',
        ]);
    }

    /**
     * ✅ NUEVO: Limpiar reportes expirados
     */
    public function cleanupExpiredReports()
    {
        $expiredReports = ReportGeneration::where('expires_at', '<', now())
            ->where('status', 'completed')
            ->get();

        $cleaned = 0;
        foreach ($expiredReports as $report) {
            $report->deleteFiles();
            $report->delete();
            $cleaned++;
        }

        return response()->json([
            'message' => "Se limpiaron {$cleaned} reportes expirados",
            'cleaned_count' => $cleaned,
        ]);
    }

    // ✅ Métodos auxiliares privados

    private function countProcessedImages(Project $project): int
    {
        return \App\Models\Image::whereHas('folder', function($q) use ($project) {
            $q->where('project_id', $project->id);
        })
            ->whereHas('processedImage', function($q) {
                $q->whereNotNull('corrected_path');
            })
            ->count();
    }

    private function estimateGenerationTime(int $imageCount): string
    {
        // ✅ Estimación más conservadora
        $minutesPerImage = match(true) {
            $imageCount > 2000 => 0.15,  // 9 segundos por imagen para proyectos masivos
            $imageCount > 1000 => 0.12,  // 7 segundos por imagen para proyectos grandes
            $imageCount > 500 => 0.10,   // 6 segundos por imagen para proyectos medianos
            default => 0.08              // 5 segundos por imagen para proyectos pequeños
        };

        $totalMinutes = $imageCount * $minutesPerImage;
        $hours = floor($totalMinutes / 60);
        $minutes = round($totalMinutes % 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        } else {
            return "{$minutes} minutos";
        }
    }

    private function downloadMultipleFiles(array $filePaths, ReportGeneration $generation)
    {
        $zipName = "informe-completo-{$generation->project->name}-" . now()->format('Y-m-d') . ".zip";
        $zipPath = storage_path("app/temp/{$zipName}");

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el archivo ZIP'], 500);
        }

        foreach ($filePaths as $filePath) {
            if (Storage::disk('local')->exists($filePath)) {
                $zip->addFile(
                    Storage::disk('local')->path($filePath),
                    basename($filePath)
                );
            }
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    /**
     * ✅ NUEVO: Verificar estado de reportes del proyecto
     */
    public function checkProjectReports(Project $project)
    {
        // Buscar el último reporte completado
        $latestCompleted = ReportGeneration::where('project_id', $project->id)
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        // Buscar si hay alguno procesando
        $currentProcessing = ReportGeneration::where('project_id', $project->id)
            ->where('status', 'processing')
            ->latest()
            ->first();

        $response = [
            'project_id' => $project->id,
            'has_available_report' => false,
            'is_generating' => false,
            'latest_report' => null,
            'current_generation' => null,
        ];

        if ($latestCompleted && !$latestCompleted->hasExpired()) {
            $response['has_available_report'] = true;
            $response['latest_report'] = [
                'id' => $latestCompleted->id,
                'created_at' => $latestCompleted->created_at,
                'completed_at' => $latestCompleted->completed_at,
                'expires_at' => $latestCompleted->expires_at,
                'total_images' => $latestCompleted->total_images,
                'size_info' => $this->getReportSizeInfo($latestCompleted),
                'download_url' => route('reports.download', ['generation' => $latestCompleted->id]),
            ];
        }

        if ($currentProcessing) {
            $response['is_generating'] = true;
            $response['current_generation'] = [
                'id' => $currentProcessing->id,
                'progress' => $currentProcessing->getProgressPercentage(),
                'processed_images' => $currentProcessing->processed_images,
                'total_images' => $currentProcessing->total_images,
                'started_at' => $currentProcessing->created_at,
            ];
        }

        return response()->json($response);
    }

    /**
     * ✅ NUEVO: Eliminar reporte específico
     */
    public function deleteReport($generationId)
    {
        $generation = ReportGeneration::findOrFail($generationId);

        // Verificar permisos si es necesario
        // if (!auth()->user()->can('delete', $generation)) { ... }

        $generation->deleteFiles();
        $generation->delete();

        return response()->json([
            'message' => 'Reporte eliminado correctamente',
            'deleted_generation_id' => $generationId,
        ]);
    }

    /**
     * ✅ HELPER: Obtener información de tamaño del reporte
     */
    private function getReportSizeInfo(ReportGeneration $generation)
    {
        if (!$generation->file_path) return null;

        $filePaths = is_array($generation->file_path) ? $generation->file_path : [$generation->file_path];
        $totalSize = 0;
        $fileCount = 0;

        foreach ($filePaths as $path) {
            if (\Storage::disk('local')->exists($path)) {
                $totalSize += \Storage::disk('local')->size($path);
                $fileCount++;
            }
        }

        return [
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'file_count' => $fileCount,
        ];
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
