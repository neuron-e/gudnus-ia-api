<?php

namespace App\Console\Commands;

use App\Jobs\HandleZipMappingJob;
use App\Models\ImageBatch;
use App\Models\ZipAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RelaunchZipAnalysis extends Command
{
    protected $signature = 'zip:relaunch {analysisId} {projectId} {--dry-run} {--start-module=1}';
    protected $description = 'Relanza procesamiento de ZIP ya extraído con numeración correcta';

    public function handle()
    {
        $analysisId = $this->argument('analysisId');
        $projectId = $this->argument('projectId');
        $dryRun = $this->option('dry-run');
        $startModule = $this->option('start-module');

        $this->info("🔍 Verificando análisis: {$analysisId}");

        // ✅ Buscar análisis
        $analysis = ZipAnalysis::find($analysisId);
        if (!$analysis) {
            $this->error("❌ Análisis no encontrado: {$analysisId}");
            return 1;
        }

        // ✅ VERIFICAR que el projectId coincida con el análisis
        if ($analysis->project_id != $projectId) {
            $this->error("❌ El proyecto {$projectId} no coincide con el análisis (proyecto {$analysis->project_id})");
            $this->line("💡 Usa: php artisan zip:relaunch {$analysisId} {$analysis->project_id}");
            return 1;
        }

        // ✅ Verificar que el proyecto existe
        $project = \App\Models\Project::find($projectId);
        if (!$project) {
            $this->error("❌ Proyecto no encontrado: {$projectId}");
            return 1;
        }

        // ✅ Verificar directorio extraído
        $extractedPath = "/home/forge/api.gudnus-ia.yayaboo.com/storage/app/temp_extract_{$analysisId}";
        if (!is_dir($extractedPath)) {
            $this->error("❌ Directorio extraído no encontrado: {$extractedPath}");
            return 1;
        }

        // ✅ Obtener imágenes del análisis
        $images = $analysis->getValidImages();
        if (empty($images)) {
            $this->error("❌ No hay imágenes válidas en el análisis");
            return 1;
        }

        $this->info("📊 Análisis encontrado:");
        $this->line("  - ID: {$analysis->id}");
        $this->line("  - Proyecto: {$analysis->project_id}");
        $this->line("  - Estado: {$analysis->status}");
        $this->line("  - Imágenes válidas: " . count($images));
        $this->line("  - Directorio: {$extractedPath}");

        // ✅ CREAR MAPPING NUMERADO CORRECTAMENTE
        $this->info("🔧 Creando mapping con módulos numerados...");

        // ✅ Ordenar imágenes alfabéticamente para consistencia
        usort($images, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $mapping = [];
        $moduleCounter = $startModule;

        foreach ($images as $image) {
            $imageName = $image['name'];

            // ✅ Determinar carpeta base del path
            $imagePath = $image['path'] ?? $imageName;
            $pathParts = explode('/', dirname($imagePath));
            $baseFolder = $pathParts[0] ?? 'ALMARAZ';

            // ✅ CREAR NOMBRE DE MÓDULO CORRECTO
            $moduleName = "{$baseFolder} / Módulo {$moduleCounter}";

            $mapping[] = [
                'imagen' => $imageName,
                'modulo' => $moduleName
            ];

            $moduleCounter++;
        }

        $this->info("📋 Mapping generado: " . count($mapping) . " imágenes");
        $this->line("  - Primer módulo: {$mapping[0]['modulo']}");
        $this->line("  - Último módulo: " . end($mapping)['modulo']);

        if ($dryRun) {
            $this->warn("🔍 DRY RUN - No se ejecutará nada");
            $this->table(['Imagen', 'Módulo'], array_slice($mapping, 0, 10));
            if (count($mapping) > 10) {
                $this->line("... y " . (count($mapping) - 10) . " más");
            }
            return 0;
        }

        // ✅ Verificar si ya existe un batch activo
        $activeBatch = ImageBatch::where('project_id', $projectId)
            ->where('status', 'processing')
            ->where('type', 'large-zip-relaunch')
            ->first();

        if ($activeBatch) {
            $this->warn("⚠️ Ya existe un batch activo para este proyecto: {$activeBatch->id}");
            $this->line("  - Estado: {$activeBatch->processed}/{$activeBatch->total}");

            if (!$this->confirm('¿Quieres cancelar el batch activo y crear uno nuevo?')) {
                $this->info("❌ Operación cancelada");
                return 0;
            }

            $activeBatch->update(['status' => 'cancelled']);
            $this->info("✅ Batch anterior cancelado");
        }

        // ✅ Crear nuevo batch
        $batch = ImageBatch::create([
            'project_id' => $projectId,
            'type' => 'large-zip-relaunch',
            'total' => count($mapping),
            'status' => 'processing',
            'temp_path' => $extractedPath,
        ]);

        $this->info("✅ Batch creado: {$batch->id}");

        // ✅ Despachar job usando archivos ya extraídos
        dispatch(new HandleZipMappingJob(
            $projectId,
            $mapping,
            null, // No ZIP path
            $batch->id,
            $extractedPath // ✅ Usar archivos ya extraídos
        ))->onQueue('images');

        $this->info("🚀 Job despachado exitosamente");
        $this->line("📊 Batch ID: {$batch->id}");
        $this->line("📁 Usando directorio: {$extractedPath}");
        $this->line("📦 Total imágenes: " . count($mapping));
        $this->line("🔢 Módulos: {$startModule} a " . ($startModule + count($mapping) - 1));

        return 0;
    }
}
