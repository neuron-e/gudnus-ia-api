<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeLargeZipJob;
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
            'zip' => 'required|file|mimes:zip,rar|max:5120000', // 5GB max
        ]);

        $file = $request->file('zip');
        $fileSizeMB = round($file->getSize() / (1024 * 1024), 2);

        Log::info("📦 Subiendo ZIP grande: {$fileSizeMB}MB para proyecto {$project->id}");

        // ✅ Generar ID único para tracking
        $analysisId = Str::uuid();

        // ✅ Guardar ZIP en storage temporal
        $zipPath = $file->storeAs(
            "temp_zips/large",
            "{$analysisId}.zip",
            'local'
        );

        // ✅ Crear registro de análisis
        $zipAnalysis = ZipAnalysis::create([
            'id' => $analysisId,
            'project_id' => $project->id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $zipPath,
            'file_size' => $file->getSize(),
            'status' => 'uploaded',
        ]);

        // ✅ Despachar job asíncrono para análisis
        dispatch(new AnalyzeLargeZipJob($analysisId))->onQueue('zip-analysis');

        return response()->json([
            'ok' => true,
            'analysis_id' => $analysisId,
            'file_size_mb' => $fileSizeMB,
            'message' => 'ZIP subido correctamente. Iniciando análisis...'
        ]);
    }

    /**
     * ✅ Obtener estado del análisis
     */
    public function getAnalysisStatus($analysisId)
    {
        $analysis = ZipAnalysis::findOrFail($analysisId);

        $response = [
            'analysis_id' => $analysisId,
            'status' => $analysis->status,
            'progress' => $analysis->progress ?? 0,
            'total_files' => $analysis->total_files,
            'valid_images' => $analysis->valid_images,
            'created_at' => $analysis->created_at,
            'updated_at' => $analysis->updated_at,
        ];

        if ($analysis->status === 'completed') {
            $response['images'] = json_decode($analysis->images_data, true);
            $response['total_images'] = count($response['images']);
        }

        if ($analysis->status === 'failed') {
            $response['error'] = $analysis->error_message;
        }

        return response()->json($response);
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

        $analysis = ZipAnalysis::findOrFail($analysisId);

        if ($analysis->status !== 'completed') {
            return response()->json([
                'error' => 'El análisis del ZIP no ha completado'
            ], 400);
        }

        $mapping = $request->input('mapping');

        // ✅ Crear batch para procesamiento
        $batch = \App\Models\ImageBatch::create([
            'project_id' => $analysis->project_id,
            'type' => 'large-zip-mapping',
            'total' => count($mapping),
            'status' => 'processing',
        ]);

        // ✅ Despachar job de procesamiento
        dispatch(new \App\Jobs\ProcessLargeZipMappingJob(
            $analysis->id,
            $mapping,
            $batch->id
        ))->onQueue('images');

        return response()->json([
            'ok' => true,
            'batch_id' => $batch->id,
            'message' => 'Iniciando procesamiento de imágenes...'
        ]);
    }
}

