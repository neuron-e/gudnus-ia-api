<?php

namespace App\Jobs;

use App\Models\ImageBatch;
use App\Models\Project;
use App\Models\Folder;
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

    public $timeout = 3600; // 1 hora
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $mapping,
        public ?string $zipPath = null, // ✅ Ahora opcional
        public int $batchId,
        public ?string $extractedPath = null // ✅ NUEVO: ruta de archivos ya extraídos
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado");
            return;
        }

        try {
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
                'errors' => 0,
                'started_at' => now()
            ]);

            // ✅ Determinar ruta de extracción
            $tempExtractPath = $this->getOrCreateExtractPath();

            if (!$tempExtractPath || !is_dir($tempExtractPath)) {
                throw new \Exception('No se pudo acceder a los archivos extraídos');
            }

            Log::info("📁 Usando directorio de extracción: {$tempExtractPath}");

            // ✅ Despachar jobs individuales para cada imagen (MÉTODO ORIGINAL)
            foreach ($this->mapping as $asignacion) {
                dispatch(new ProcessZipImageJob(
                    $this->projectId,
                    $asignacion,
                    $tempExtractPath,
                    $this->batchId
                ))->onQueue('images');
            }

            Log::info("✅ Despachados " . count($this->mapping) . " ProcessZipImageJob para batch {$this->batchId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error en HandleZipMappingJob: " . $e->getMessage(), [
                'batch_id' => $this->batchId,
                'trace' => $e->getTraceAsString()
            ]);

            $batch->update([
                'status' => 'failed',
                'error_messages' => [$e->getMessage()],
                'completed_at' => now()
            ]);
        } finally {
            // ✅ Limpiar archivos temporales SOLO si los creamos nosotros
            if ($this->zipPath && $this->extractedPath === null) {
                $this->cleanup($tempExtractPath);
            }
        }
    }

    /**
     * ✅ Obtener o crear ruta de extracción
     */
    private function getOrCreateExtractPath(): ?string
    {
        // ✅ Caso 1: Usar archivos ya extraídos (flujo nuevo con análisis previo)
        if ($this->extractedPath) {
            Log::info("📁 Usando archivos ya extraídos: {$this->extractedPath}");

            if (is_dir($this->extractedPath)) {
                return $this->extractedPath;
            } else {
                Log::error("❌ Directorio extraído no existe: {$this->extractedPath}");
                return null;
            }
        }

        // ✅ Caso 2: Extraer ZIP nosotros (flujo original)
        if ($this->zipPath && file_exists($this->zipPath)) {
            Log::info("📦 Extrayendo ZIP: {$this->zipPath}");
            return $this->extractZipFile();
        }

        throw new \Exception('No hay ZIP ni archivos extraídos disponibles');
    }

    /**
     * ✅ Extraer ZIP (lógica original)
     */
    private function extractZipFile(): string
    {
        $tempExtractPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());

        // ✅ Limpiar directorio si existe
        if (File::exists($tempExtractPath)) {
            File::deleteDirectory($tempExtractPath);
        }
        File::makeDirectory($tempExtractPath, 0755, true);

        $zip = new \ZipArchive;
        if ($zip->open($this->zipPath) !== true) {
            throw new \Exception('No se pudo abrir el ZIP');
        }

        if (!$zip->extractTo($tempExtractPath)) {
            $zip->close();
            throw new \Exception('No se pudo extraer el ZIP');
        }

        $zip->close();

        Log::info("📂 ZIP extraído correctamente a: {$tempExtractPath}");
        return $tempExtractPath;
    }

    /**
     * ✅ Limpiar archivos temporales (solo si los creamos nosotros)
     */
    private function cleanup(string $tempPath): void
    {
        try {
            if (is_dir($tempPath)) {
                File::deleteDirectory($tempPath);
                Log::info("🗑️ Directorio temporal eliminado: {$tempPath}");
            }

            if ($this->zipPath && file_exists($this->zipPath)) {
                @unlink($this->zipPath);
                Log::info("🗑️ ZIP eliminado: {$this->zipPath}");
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ Error limpiando archivos temporales: " . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ HandleZipMappingJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update([
                'status' => 'failed',
                'error_messages' => [$exception->getMessage()],
                'completed_at' => now()
            ]);
        }
    }
}
