<?php

namespace App\Console\Commands;

use App\Jobs\HandleZipMappingJob;
use App\Models\ImageBatch;
use App\Models\ZipAnalysis;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RelaunchZipAnalysis extends Command
{
    protected $signature = 'zip:relaunch {analysisId} {projectId} {--dry-run}';
    protected $description = 'Relanza procesamiento de ZIP ya extraído';

    public function handle()
    {
        $analysisId = $this->argument('analysisId');
        $projectId = $this->argument('projectId');
        $dryRun = $this->option('dry-run');

        $this->info("🔍 Verificando análisis: {$analysisId}");

        // ✅ Buscar análisis
        $analysis = ZipAnalysis::find($analysisId);
        if (!$analysis) {
            $this->error("❌ Análisis no encontrado: {$analysisId}");
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

        // ✅ Crear mapping simple (todas las imágenes al módulo raíz)
        $mapping = [];
        foreach ($images as $image) {
            $imagePath = $image['path'] ?? $image['name'];

            // ✅ Extraer módulo del path
            $parts = explode('/', dirname($imagePath));
            $moduleName = $parts[0] ?? 'SIN_MODULO';

            $mapping[] = [
                'imagen' => $image['name'],
                'modulo' => $moduleName
            ];
        }

        $this->info("📋 Mapping generado para " . count($mapping) . " imágenes");

        if ($dryRun) {
            $this->warn("🔍 DRY RUN - No se ejecutará nada");
            $this->table(['Imagen', 'Módulo'], array_slice($mapping, 0, 10));
            if (count($mapping) > 10) {
                $this->line("... y " . (count($mapping) - 10) . " más");
            }
            return 0;
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

        return 0;
    }
}
