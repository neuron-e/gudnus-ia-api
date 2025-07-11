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
use Illuminate\Support\Facades\DB;

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
     * ✅ Procesar asignación de ZIP analizado CON CREACIÓN AUTOMÁTICA DE MÓDULOS
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

            // ✅ NUEVO: VERIFICAR Y CREAR MÓDULOS AUTOMÁTICAMENTE
            $modulesCreated = $this->ensureModulesExist($projectId, $mapping);

            if ($modulesCreated > 0) {
                Log::info("✅ Creados {$modulesCreated} módulos automáticamente para proyecto {$projectId}");
            }

            // ✅ Crear batch igual que en uploadWithMapping
            $batch = ImageBatch::create([
                'project_id' => $projectId,
                'type' => 'large-zip-mapping',
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
                'extracted_path' => $extractedPath,
                'modules_created' => $modulesCreated
            ]);

            return response()->json([
                'ok' => true,
                'msg' => 'ZIP recibido correctamente. Se está procesando en segundo plano...',
                'batch_id' => $batch->id,
                'analysis_id' => $analysisId,
                'modules_created' => $modulesCreated
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error procesando ZIP analizado {$analysisId}: " . $e->getMessage());

            return response()->json([
                'error' => 'Error procesando el ZIP: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ✅ FUNCIÓN CORREGIDA: Asegurar que existen los módulos necesarios
     */
    private function ensureModulesExist(int $projectId, array $mapping): int
    {
        Log::info("🔍 Verificando módulos necesarios para proyecto {$projectId}");

        // Obtener todos los módulos únicos del mapping
        $modulesNeeded = [];
        foreach ($mapping as $item) {
            $modulePath = trim($item['modulo']);
            if (!empty($modulePath)) {
                $modulesNeeded[$modulePath] = true;
            }
        }

        $uniqueModules = array_keys($modulesNeeded);
        Log::info("📋 Módulos únicos necesarios: " . count($uniqueModules));

        // ✅ Verificar cuáles existen usando full_path
        $existingModules = Folder::where('project_id', $projectId)
            ->whereIn('full_path', $uniqueModules)
            ->pluck('full_path')
            ->toArray();

        $missingModules = array_diff($uniqueModules, $existingModules);

        Log::info("📊 Módulos existentes: " . count($existingModules) . ", Faltantes: " . count($missingModules));

        if (empty($missingModules)) {
            Log::info("✅ Todos los módulos ya existen");
            return 0;
        }

        // Crear módulos faltantes
        return $this->createMissingModules($projectId, $missingModules);
    }

    /**
     * ✅ NUEVA FUNCIÓN: Crear módulos faltantes
     */
    private function createMissingModules(int $projectId, array $missingModules): int
    {
        Log::info("🔧 Creando " . count($missingModules) . " módulos faltantes para proyecto {$projectId}");

        $created = 0;

        try {
            DB::beginTransaction();

            foreach ($missingModules as $modulePath) {
                $parts = explode(' / ', $modulePath);
                $parentId = null;

                foreach ($parts as $index => $part) {
                    // ✅ Determinar tipo basado en la posición
                    $type = ($index === count($parts) - 1) ? 'modulo' : 'folder';

                    // ✅ Buscar si ya existe este folder con este parent
                    $existing = Folder::where('project_id', $projectId)
                        ->where('parent_id', $parentId)
                        ->where('name', $part)
                        ->first();

                    if (!$existing) {
                        // ✅ Crear usando Eloquent para que funcionen los métodos del modelo
                        $folder = Folder::create([
                            'project_id' => $projectId,
                            'parent_id' => $parentId,
                            'name' => $part,
                            'type' => $type,
                        ]);

                        // ✅ Generar full_path usando el método del modelo
                        $folder->full_path = $folder->generateFullPath();
                        $folder->save();

                        Log::debug("✅ Creado: '{$folder->full_path}' (ID: {$folder->id}, Tipo: {$type})");
                        $created++;
                        $parentId = $folder->id;
                    } else {
                        // ✅ Usar el ID existente como parent para el siguiente nivel
                        $parentId = $existing->id;
                        Log::debug("🔁 Ya existe: '{$existing->full_path}' (ID: {$existing->id})");

                        // ✅ Verificar y corregir full_path si está vacío
                        if (empty($existing->full_path)) {
                            $existing->full_path = $existing->generateFullPath();
                            $existing->save();
                            Log::debug("🔧 Corregido full_path: '{$existing->full_path}'");
                        }
                    }
                }
            }

            DB::commit();
            Log::info("✅ Creados {$created} módulos/folders correctamente");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("❌ Error creando módulos: " . $e->getMessage());
            throw $e;
        }

        return $created;
    }

    private function estimateAnalysisTime($fileSize): int
    {
        // Estimación basada en tamaño: ~1 minuto por cada 100MB
        $sizeMB = $fileSize / 1024 / 1024;
        $minutes = max(1, ceil($sizeMB / 100));

        return min($minutes, 30); // Máximo 30 minutos estimado
    }
}
