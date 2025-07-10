<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateDownloadZipJob;
use App\Models\DownloadBatch;
use App\Models\Project;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    /**
     * ✅ Iniciar descarga masiva asíncrona
     */
    public function startMassiveDownload(Request $request, Project $project)
    {
        $request->validate([
            'type' => 'required|in:original,processed,analyzed,all',
            'force_new' => 'nullable|boolean' // ✅ NUEVO: Permitir forzar nueva descarga
        ]);

        $type = $request->type;

        // ✅ Obtener count real de imágenes según el tipo
        $imageCount = $this->getImageCountByType($project->id, $type);

        if ($imageCount === 0) {
            return response()->json(['error' => 'No hay imágenes del tipo solicitado para descargar'], 404);
        }

        // ✅ Verificar si ya hay un batch activo del mismo tipo
        $activeBatch = DownloadBatch::where('project_id', $project->id)
            ->where('type', $type)
            ->whereIn('status', ['pending', 'processing'])
            ->first();

        if ($activeBatch) {
            return response()->json([
                'ok' => true,
                'message' => 'Ya hay una descarga en progreso para este tipo',
                'batch_id' => $activeBatch->id,
                'status' => $activeBatch->status,
                'progress' => $activeBatch->getProgressPercentage(),
                'existing' => true
            ]);
        }

        // ✅ Verificar si hay un batch completado reciente (últimas 6 horas) SOLO si no se fuerza nueva descarga
        if (!$request->input('force_new', false)) {
            $recentBatch = DownloadBatch::where('project_id', $project->id)
                ->where('type', $type)
                ->where('status', 'completed')
                ->where('created_at', '>', now()->subHours(6))
                ->where(function($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->orderBy('created_at', 'desc')
                ->first();

            if ($recentBatch && $recentBatch->isReady()) {
                return response()->json([
                    'ok' => true,
                    'message' => 'Hay una descarga reciente disponible',
                    'batch_id' => $recentBatch->id,
                    'status' => $recentBatch->status,
                    'progress' => 100,
                    'existing_download' => true,
                    'download_urls' => $this->formatDownloadUrls($recentBatch),
                    'created_at' => $recentBatch->created_at,
                    'can_force_new' => true // ✅ Indicar que puede crear nueva
                ]);
            }
        }

        // ✅ Limpiar batches antiguos
        $this->cleanupOldBatches($project->id, $type);

        // ✅ Crear nuevo batch con conteo correcto
        $batch = DownloadBatch::create([
            'project_id' => $project->id,
            'type' => $type,
            'status' => 'pending',
            'total_images' => $imageCount,
            'processed_images' => 0
        ]);

        // ✅ Despachar job asíncrono
        dispatch(new GenerateDownloadZipJob($project->id, $type, $batch->id))
            ->onQueue('downloads');

        Log::info("✅ Descarga masiva iniciada", [
            'project_id' => $project->id,
            'type' => $type,
            'batch_id' => $batch->id,
            'total_images' => $imageCount
        ]);

        return response()->json([
            'ok' => true,
            'message' => "Descarga de {$imageCount} imágenes iniciada en segundo plano",
            'batch_id' => $batch->id,
            'estimated_time_minutes' => $this->estimateDownloadTime($imageCount, $type),
            'total_images' => $imageCount,
            'new_generation' => true,
            'forced_new' => $request->input('force_new', false) // ✅ Indicar si fue forzada
        ]);
    }

    /**
     * ✅ Obtener estado de descarga
     */
    public function getDownloadStatus($batchId)
    {
        $batch = DownloadBatch::findOrFail($batchId);

        // ✅ Calcular progreso real
        $progress = $batch->getProgressPercentage();

        // ✅ Si está procesando pero sin progreso por mucho tiempo, marcar como stuck
        $isStuck = false;
        if ($batch->status === 'processing' && $batch->updated_at->addMinutes(10) < now()) {
            $isStuck = true;
        }

        $data = [
            'id' => $batch->id,
            'project_id' => $batch->project_id,
            'type' => $batch->type,
            'status' => $batch->status,
            'progress' => $progress,
            'total_images' => $batch->total_images,
            'processed_images' => $batch->processed_images,
            'created_at' => $batch->created_at,
            'started_at' => $batch->started_at,
            'completed_at' => $batch->completed_at,
            'expires_at' => $batch->expires_at,
            'is_expired' => $batch->hasExpired(),
            'is_stuck' => $isStuck,
            'error' => $batch->error,
            'is_ready' => $batch->isReady()
        ];

        // ✅ Agregar URLs de descarga si está completado y listo
        if ($batch->isReady()) {
            $data['download_urls'] = $this->formatDownloadUrls($batch);
        }

        return response()->json($data);
    }

    /**
     * ✅ Descargar archivo específico
     */
    public function downloadFile($batchId, $filename = null)
    {
        $batch = DownloadBatch::findOrFail($batchId);

        if (!$batch->isReady()) {
            return response()->json([
                'error' => 'La descarga no está lista o ha expirado',
                'status' => $batch->status,
                'expired' => $batch->hasExpired()
            ], 410);
        }

        $filePaths = $batch->file_paths;
        if (!$filePaths || empty($filePaths)) {
            return response()->json(['error' => 'No hay archivos disponibles'], 404);
        }

        // ✅ Si especifica filename, buscar ese archivo
        if ($filename) {
            $targetPath = collect($filePaths)->first(function ($path) use ($filename) {
                return basename($path) === $filename;
            });

            if (!$targetPath || !file_exists($targetPath)) {
                return response()->json(['error' => 'Archivo no encontrado: ' . $filename], 404);
            }

            return response()->download($targetPath);
        }

        // ✅ Si solo hay un archivo, descarga directa
        if (count($filePaths) === 1) {
            $filePath = $filePaths[0];
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'Archivo no encontrado'], 404);
            }
            return response()->download($filePath);
        }

        // ✅ Si hay múltiples archivos, crear ZIP temporal con todos
        return $this->downloadMultipleFiles($filePaths, $batch);
    }

    /**
     * ✅ Listar todas las descargas del proyecto
     */
    public function listProjectDownloads(Project $project)
    {
        $downloads = DownloadBatch::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->limit(10) // Solo últimas 10
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'type' => $batch->type,
                    'status' => $batch->status,
                    'progress' => $batch->getProgressPercentage(),
                    'total_images' => $batch->total_images,
                    'processed_images' => $batch->processed_images,
                    'created_at' => $batch->created_at,
                    'completed_at' => $batch->completed_at,
                    'expires_at' => $batch->expires_at,
                    'is_expired' => $batch->hasExpired(),
                    'is_ready' => $batch->isReady(),
                    'file_count' => $batch->file_paths ? count($batch->file_paths) : 0,
                    'download_urls' => $batch->isReady() ? $this->formatDownloadUrls($batch) : null
                ];
            });

        return response()->json($downloads);
    }

    /**
     * ✅ Cancelar descarga
     */
    public function cancelDownload($batchId)
    {
        $batch = DownloadBatch::findOrFail($batchId);

        if (!in_array($batch->status, ['pending', 'processing'])) {
            return response()->json([
                'error' => 'La descarga no se puede cancelar',
                'status' => $batch->status
            ], 400);
        }

        $batch->update([
            'status' => 'cancelled',
            'error' => 'Cancelado por el usuario'
        ]);

        // ✅ Limpiar archivos parciales si existen
        if ($batch->file_paths) {
            foreach ($batch->file_paths as $path) {
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
            $batch->update(['file_paths' => null]);
        }

        Log::info("Descarga cancelada: batch {$batchId}");

        return response()->json([
            'ok' => true,
            'message' => 'Descarga cancelada correctamente'
        ]);
    }

    // ✅ Métodos privados mejorados

    /**
     * ✅ Obtener conteo real de imágenes según tipo
     */
    private function getImageCountByType($projectId, $type): int
    {
        $query = Image::whereHas('folder', fn($q) => $q->where('project_id', $projectId));

        switch ($type) {
            case 'original':
                return $query->whereNotNull('original_path')->count();

            case 'processed':
                return $query->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                )->count();

            case 'analyzed':
                return $query->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                    ->whereNotNull('ai_response_json')
                )->count();

            case 'all':
                return $query->count();

            default:
                return 0;
        }
    }

    /**
     * ✅ Formatear URLs de descarga
     */
    private function formatDownloadUrls($batch): array
    {
        if (!$batch->file_paths) return [];

        return collect($batch->file_paths)->map(function($path) use ($batch) {
            $filename = basename($path);
            return [
                'filename' => $filename,
                'url' => route('downloads.file', ['batchId' => $batch->id, 'filename' => $filename]),
                'size' => file_exists($path) ? $this->formatFileSize(filesize($path)) : 'Desconocido'
            ];
        })->toArray();
    }

    private function cleanupOldBatches($projectId, $type)
    {
        DownloadBatch::where('project_id', $projectId)
            ->where('type', $type)
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->where('created_at', '<', now()->subHours(12))
            ->chunk(50, function($batches) {
                foreach ($batches as $batch) {
                    // Eliminar archivos físicos
                    if ($batch->file_paths) {
                        foreach ($batch->file_paths as $path) {
                            if (file_exists($path)) {
                                @unlink($path);
                            }
                        }
                    }
                    $batch->delete();
                }
            });
    }

    private function estimateDownloadTime($imageCount, $type): int
    {
        $factor = match($type) {
            'original' => 1,
            'processed' => 1.2,
            'analyzed' => 2.5,
            'all' => 3
        };

        $baseMinutes = ceil($imageCount / 100) * $factor;
        return max(2, min($baseMinutes, 120));
    }

    private function formatFileSize($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function downloadMultipleFiles($filePaths, $batch)
    {
        $zipName = "download_complete_{$batch->project_id}_{$batch->type}_" . now()->format('Ymd_His') . ".zip";
        $zipPath = storage_path("app/tmp/{$zipName}");

        // Crear directorio tmp si no existe
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear ZIP combinado'], 500);
        }

        foreach ($filePaths as $index => $filePath) {
            if (file_exists($filePath)) {
                $zip->addFile($filePath, basename($filePath));
            }
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
