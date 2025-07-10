<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeLargeZipJob;
use App\Jobs\HandleZipMappingJob;
use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Models\Project;
use App\Models\ZipAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LargeZipController extends Controller
{
    /**
     * ✅ Subir ZIP grande sin análisis previo
     */
    public function uploadLargeZip(Request $request, Project $project)
    {
        $request->validate([
            'zip' => 'required|file|mimes:zip|max:5242880', // 5GB máximo
        ]);

        try {
            $file = $request->file('zip');
            $originalFilename = $file->getClientOriginalName();
            $fileSize = $file->getSize();

            Log::info("📤 Subiendo ZIP grande", [
                'project_id' => $project->id,
                'filename' => $originalFilename,
                'size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            // ✅ Generar paths únicos
            $analysisId = 'zip_analysis_' . uniqid();
            $zipFileName = $analysisId . '.zip';
            $zipStoragePath = "temp_zips/{$zipFileName}";

            // ✅ Asegurar que existe el directorio temp_zips
            $tempZipsDir = storage_path('app/temp_zips');
            if (!file_exists($tempZipsDir)) {
                mkdir($tempZipsDir, 0755, true);
            }

            // ✅ Guardar ZIP en storage
            $file->move($tempZipsDir, $zipFileName);

            // ✅ Crear registro de análisis con campos correctos
            $analysis = ZipAnalysis::create([
                'id' => $analysisId,
                'project_id' => $project->id,
                'original_filename' => $originalFilename,
                'file_size' => $fileSize,
                'file_path' => $zipStoragePath,
                'status' => 'uploaded', // ✅ Usar estado inicial correcto
                'progress' => 0
            ]);

            // ✅ Despachar job de análisis
            dispatch(new AnalyzeLargeZipJob($analysis->id))->onQueue('zip-analysis');

            Log::info("✅ ZIP grande guardado y análisis iniciado", [
                'analysis_id' => $analysisId,
                'project_id' => $project->id,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2)
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'ZIP subido correctamente, iniciando análisis...',
                'analysis_id' => $analysisId,
                'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                'estimated_time_minutes' => $this->estimateAnalysisTime($fileSize)
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error subiendo ZIP grande: " . $e->getMessage());

            return response()->json([
                'error' => 'Error subiendo el archivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ Obtener estado del análisis
     */
    public function getAnalysisStatus($analysisId)
    {
        try {
            $analysis = ZipAnalysis::findOrFail($analysisId);

            $data = [
                'id' => $analysis->id,
                'project_id' => $analysis->project_id,
                'status' => $analysis->status,
                'progress' => $analysis->progress ?? 0,
                'total_files' => $analysis->total_files,
                'valid_images' => $analysis->valid_images, // ✅ Usar nombre correcto
                'created_at' => $analysis->created_at,
                'is_expired' => $analysis->hasExpired(),
                'error' => $analysis->error_message
            ];

            // ✅ Agregar datos de imágenes si está completado
            if ($analysis->status === 'completed' && $analysis->images_data) {
                $data['images'] = $analysis->getValidImages();
                $data['total_images'] = count($data['images']); // ✅ Calcular dinámicamente
            }

            return response()->json($data);

        } catch (\Exception $e) {
            Log::error("❌ Error obteniendo estado de análisis {$analysisId}: " . $e->getMessage());

            return response()->json([
                'error' => 'Análisis no encontrado'
            ], 404);
        }
    }

    /**
     * ✅ Procesar asignación de ZIP analizado
     */
    public function processAnalyzedZip(Request $request, $analysisId)
    {
        $request->validate([
            'mapping' => 'required|array',
            'mapping.*.modulo' => 'required|string',
            'mapping.*.imagen' => 'required|string',
        ]);

        try {
            // ✅ Buscar el análisis
            $analysis = ZipAnalysis::where('id', $analysisId)
                ->where('status', 'completed')
                ->first();

            if (!$analysis) {
                return response()->json([
                    'error' => 'Análisis de ZIP no encontrado o no completado'
                ], 404);
            }

            // ✅ Verificar que no haya expirado
            if ($analysis->hasExpired()) {
                return response()->json([
                    'error' => 'El análisis de ZIP ha expirado'
                ], 410);
            }

            // ✅ Verificar que los archivos extraídos existan
            $extractedPath = $analysis->getExtractedPath();
            if (!is_dir($extractedPath)) {
                return response()->json([
                    'error' => 'Los archivos extraídos no están disponibles'
                ], 410);
            }

            $mapping = $request->input('mapping');
            $projectId = $analysis->project_id;

            Log::info("📤 Procesando ZIP analizado {$analysisId} con " . count($mapping) . " asignaciones");

            // ✅ Crear batch igual que en uploadWithMapping
            $batch = ImageBatch::create([
                'project_id' => $projectId,
                'type' => 'zip-mapping',
                'total' => count($mapping),
                'status' => 'processing',
                'temp_path' => $extractedPath, // ✅ Usar carpeta ya extraída
            ]);

            // ✅ CLAVE: Usar el mismo job pero con archivos ya extraídos
            dispatch(new HandleZipMappingJob(
                $projectId,
                $mapping,
                null, // ✅ No hay ZIP path porque ya está extraído
                $batch->id,
                $extractedPath // ✅ NUEVO parámetro: usar carpeta extraída
            ));

            Log::info("✅ ZIP analizado enviado a procesamiento", [
                'analysis_id' => $analysisId,
                'project_id' => $projectId,
                'batch_id' => $batch->id,
                'mapping_count' => count($mapping),
                'extracted_path' => $extractedPath
            ]);

            return response()->json([
                'ok' => true,
                'msg' => 'ZIP recibido correctamente. Se está procesando en segundo plano...',
                'batch_id' => $batch->id,
                'analysis_id' => $analysisId
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error procesando ZIP analizado {$analysisId}: " . $e->getMessage());

            return response()->json([
                'error' => 'Error procesando el ZIP: ' . $e->getMessage()
            ], 500);
        }
    }

    private function estimateAnalysisTime($fileSize): int
    {
        // Estimación basada en tamaño: ~1 minuto por cada 100MB
        $sizeMB = $fileSize / 1024 / 1024;
        $minutes = max(1, ceil($sizeMB / 100));

        return min($minutes, 30); // Máximo 30 minutos estimado
    }
}
