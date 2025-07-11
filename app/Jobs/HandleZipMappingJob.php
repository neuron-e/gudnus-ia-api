<?php

namespace App\Jobs;

use App\Models\ImageBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessZipImageJob;

class HandleZipMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $mapping,
        public ?string $zipPath,
        public int $batchId,
        public ?string $extractedPath = null // ✅ NUEVO: Permitir usar archivos ya extraídos
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado");
            return;
        }

        Log::info("🚀 Iniciando HandleZipMappingJob", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'mapping_count' => count($this->mapping),
            'zip_path' => $this->zipPath,
            'extracted_path' => $this->extractedPath
        ]);

        // ✅ Marcar como procesando y establecer total
        $batch->update([
            'status' => 'processing',
            'total' => count($this->mapping),
            'processed' => 0,
            'errors' => 0
        ]);

        try {
            $tempPath = $this->prepareExtractionPath();

            Log::info("📁 Usando directorio de extracción: {$tempPath}");

            // ✅ Despachar TODOS los jobs
            foreach ($this->mapping as $asignacion) {
                dispatch(new ProcessZipImageJob(
                    $this->projectId,
                    $asignacion,
                    $tempPath,
                    $this->batchId
                ))->onQueue('images');
            }

            Log::info("✅ Despachados " . count($this->mapping) . " ProcessZipImageJob para batch {$this->batchId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error en HandleZipMappingJob: " . $e->getMessage());
            $batch->update(['status' => 'failed']);
        } finally {
            // ✅ Limpiar ZIP original solo si se proporcionó y se extrajo
            if ($this->zipPath && file_exists($this->zipPath) && !$this->extractedPath) {
                @unlink($this->zipPath);
                Log::debug("🗑️ ZIP original eliminado: {$this->zipPath}");
            }
        }
    }

    /**
     * ✅ NUEVA FUNCIÓN: Preparar directorio de extracción
     */
    private function prepareExtractionPath(): string
    {
        // Si ya hay archivos extraídos, usarlos
        if ($this->extractedPath && is_dir($this->extractedPath)) {
            Log::info("📁 Usando archivos ya extraídos: {$this->extractedPath}");
            return $this->extractedPath;
        }

        // Si no, extraer el ZIP
        if (!$this->zipPath || !file_exists($this->zipPath)) {
            throw new \Exception("No se proporcionó ZIP path válido y no hay archivos extraídos");
        }

        // Crear directorio temporal para extracción
        $tempPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());
        if (File::exists($tempPath)) {
            File::deleteDirectory($tempPath);
        }
        File::makeDirectory($tempPath, 0755, true);

        // Extraer ZIP
        $zip = new \ZipArchive;
        if ($zip->open($this->zipPath) !== true) {
            throw new \Exception("No se pudo abrir el ZIP: {$this->zipPath}");
        }

        if (!$zip->extractTo($tempPath)) {
            $zip->close();
            throw new \Exception("No se pudo extraer el ZIP a: {$tempPath}");
        }

        $zip->close();
        Log::info("✅ ZIP extraído en: {$tempPath}");

        return $tempPath;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ HandleZipMappingJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }

        // Limpiar archivos en caso de fallo
        if ($this->zipPath && file_exists($this->zipPath)) {
            @unlink($this->zipPath);
        }
    }
}
