<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use App\Models\Folder;
use App\Models\Image;
use App\Services\StorageManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessZipExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutos
    public $tries = 2;

    public function __construct(public int $batchId)
    {
        $this->onQueue('zip-processing');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ ProcessZipExtractionJob: Batch {$this->batchId} no encontrado");
            return;
        }

        if ($batch->isCancelled()) {
            $batch->logInfo("Job de extracción cancelado - batch en estado: {$batch->status}");
            $batch->decrementActiveJobs();
            return;
        }

        $batch->logInfo("📦 Iniciando extracción y mapeo de ZIP");

        try {
            $config = $batch->config ?? [];
            $zipPath = $config['zip_path'] ?? null;
            $mapping = $config['mapping'] ?? [];
            $projectId = $batch->project_id;

            if (!$zipPath || empty($mapping)) {
                throw new \Exception("Faltan zip_path o mapping en configuración del batch");
            }

            // ✅ PASO 1: Extraer ZIP
            $extractedPath = $this->extractZip($zipPath, $batch);

            // ✅ PASO 2: Validar archivos extraídos
            $validatedMapping = $this->validateExtractedFiles($extractedPath, $mapping, $batch);

            // ✅ PASO 3: Crear registros de imágenes y despachar procesamiento
            $createdImages = $this->createImageRecords($validatedMapping, $projectId, $extractedPath, $batch);

            // ✅ PASO 4: Despachar jobs de procesamiento para cada imagen
            $this->dispatchImageProcessingJobs($createdImages, $batch);

            $batch->logInfo("✅ Extracción de ZIP completada - " . count($createdImages) . " imágenes creadas");

        } catch (\Throwable $e) {
            $batch->logError("Error en extracción de ZIP: " . $e->getMessage());
            $this->handleZipFailure($batch, $e);
        }
    }

    /**
     * 📁 PASO 1: Extraer archivo ZIP
     */
    private function extractZip(string $zipPath, UnifiedBatch $batch): string
    {
        if (!file_exists($zipPath)) {
            throw new \Exception("Archivo ZIP no encontrado: {$zipPath}");
        }

        $storageManager = app(StorageManager::class);
        $tempPath = $storageManager->createTempDirectory($batch->project_id, $batch->id);

        $batch->logInfo("📁 Extrayendo ZIP a: {$tempPath}");

        $zip = new \ZipArchive;
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new \Exception("No se pudo abrir el ZIP. Código de error: {$result}");
        }

        if (!$zip->extractTo($tempPath)) {
            $zip->close();
            throw new \Exception("No se pudo extraer el ZIP a: {$tempPath}");
        }

        $extractedFileCount = $zip->numFiles;
        $zip->close();

        $batch->logInfo("✅ ZIP extraído exitosamente: {$extractedFileCount} archivos");

        // ✅ Limpiar ZIP original
        if (file_exists($zipPath)) {
            unlink($zipPath);
            $batch->logInfo("🗑️ ZIP original eliminado: {$zipPath}");
        }

        return $tempPath;
    }

    /**
     * ✅ PASO 2: Validar archivos extraídos contra mapping
     */
    private function validateExtractedFiles(string $extractedPath, array $mapping, UnifiedBatch $batch): array
    {
        $validatedMapping = [];
        $foundFiles = 0;
        $missingFiles = 0;

        foreach ($mapping as $mapItem) {
            $imageName = $mapItem['imagen'] ?? '';
            $moduleName = $mapItem['modulo'] ?? '';

            if (empty($imageName) || empty($moduleName)) {
                $batch->logError("Elemento de mapping inválido: " . json_encode($mapItem));
                continue;
            }

            // ✅ Buscar archivo en directorio extraído
            $foundPath = $this->findImageInExtracted($extractedPath, $imageName);

            if ($foundPath) {
                $validatedMapping[] = [
                    'imagen' => $imageName,
                    'modulo' => $moduleName,
                    'extracted_path' => $foundPath,
                    'original_mapping' => $mapItem
                ];
                $foundFiles++;
            } else {
                $batch->logError("Imagen no encontrada en ZIP: {$imageName}");
                $missingFiles++;
            }
        }

        $batch->logInfo("📊 Validación: {$foundFiles} encontradas, {$missingFiles} faltantes");

        if (empty($validatedMapping)) {
            throw new \Exception("No se encontró ninguna imagen válida en el ZIP");
        }

        // ✅ Actualizar total real del batch
        $batch->update(['total_items' => count($validatedMapping)]);

        return $validatedMapping;
    }

    /**
     * 🔍 Buscar imagen en archivos extraídos
     */
    private function findImageInExtracted(string $extractedPath, string $imageName): ?string
    {
        // ✅ Buscar por nombre exacto primero
        $exactPath = $extractedPath . DIRECTORY_SEPARATOR . $imageName;
        if (file_exists($exactPath)) {
            return $exactPath;
        }

        // ✅ Buscar recursivamente en subdirectorios
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractedPath)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $imageName) {
                return $file->getPathname();
            }
        }

        // ✅ Buscar por nombre sin extensión (tolerancia)
        $nameWithoutExt = pathinfo($imageName, PATHINFO_FILENAME);

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileNameWithoutExt = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                if (strtolower($fileNameWithoutExt) === strtolower($nameWithoutExt)) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * 🏗️ PASO 3: Crear registros de imágenes
     */
    private function createImageRecords(array $validatedMapping, int $projectId, string $extractedPath, UnifiedBatch $batch): array
    {
        $storageManager = app(StorageManager::class);
        $createdImages = [];
        $folders = $this->getFoldersCache($projectId);

        foreach ($validatedMapping as $item) {
            try {
                $moduleName = $item['modulo'];
                $imageName = $item['imagen'];
                $localPath = $item['extracted_path'];

                // ✅ Buscar o crear carpeta
                $folder = $this->findOrCreateFolder($folders, $moduleName, $projectId);

                // ✅ Subir imagen a Wasabi con nueva estructura
                $wasabiPath = $storageManager->storeOriginalImage(
                    new \Illuminate\Http\UploadedFile(
                        $localPath,
                        $imageName,
                        mime_content_type($localPath),
                        null,
                        true // test=true para aceptar archivo existente
                    ),
                    $projectId,
                    $this->batchId,
                    $folder->id,
                    $imageName
                );

                // ✅ Crear registro de imagen (adaptado a estructura actual)
                $image = Image::create([
                    'folder_id' => $folder->id,
                    'project_id' => $projectId,
                    'original_path' => $wasabiPath,
                    'status' => 'pending',
                    'is_processed' => false,
                    'is_counted' => false
                ]);

                $createdImages[] = $image;

                $batch->logInfo("📸 Imagen creada: {$imageName} → {$moduleName}");

            } catch (\Throwable $e) {
                $batch->logError("Error creando imagen {$item['imagen']}: " . $e->getMessage());
                $batch->incrementFailed("Error creando imagen: " . $e->getMessage());
            }
        }

        return $createdImages;
    }

    /**
     * 🚀 PASO 4: Despachar jobs de procesamiento individual
     */
    private function dispatchImageProcessingJobs(array $images, UnifiedBatch $batch): void
    {
        $config = $batch->config ?? [];
        $operation = $config['process_operation'] ?? 'crop'; // crop, analyze, both

        // ✅ Reset active jobs y preparar para nuevos jobs
        $batch->update(['active_jobs' => 0]);

        foreach ($images as $index => $image) {
            // ✅ Delay progresivo para evitar saturación
            $delay = $index * 2; // 2 segundos entre cada job

            ProcessSingleImageJob::dispatch($image->id, $operation, $batch->id)
                ->delay(now()->addSeconds($delay))
                ->onQueue('atomic-images');

            $batch->incrementActiveJobs();
        }

        $batch->logInfo("🚀 Despachados " . count($images) . " jobs de procesamiento individual");

        // ✅ Marcar la extracción como completada
        $batch->decrementActiveJobs(); // Decrementar el job de extracción
    }

    // ==================== MÉTODOS DE APOYO ====================

    /**
     * 📁 Cache de carpetas para evitar queries repetidas
     */
    private function getFoldersCache(int $projectId): \Illuminate\Support\Collection
    {
        return Folder::where('project_id', $projectId)->get()->keyBy('name');
    }

    /**
     * 🔍 Buscar o crear carpeta
     */
    private function findOrCreateFolder($folders, string $moduleName, int $projectId): Folder
    {
        // ✅ Buscar existente
        if ($folders->has($moduleName)) {
            return $folders->get($moduleName);
        }

        // ✅ Crear nueva
        $folder = Folder::create([
            'project_id' => $projectId,
            'name' => $moduleName,
            'type' => 'modulo',
            'parent_id' => null
        ]);

        // ✅ Agregar al cache
        $folders->put($moduleName, $folder);

        return $folder;
    }

    /**
     * 💥 Manejar fallo de extracción
     */
    private function handleZipFailure(UnifiedBatch $batch, \Throwable $e): void
    {
        $batch->update([
            'status' => 'failed',
            'active_jobs' => 0,
            'completed_at' => now(),
            'last_error' => $e->getMessage()
        ]);

        // ✅ Programar limpieza inmediata
        CleanupBatchFilesJob::dispatch($batch->id)
            ->delay(now()->addMinutes(1))
            ->onQueue('maintenance');
    }

    /**
     * ✅ Manejo de fallos
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessZipExtractionJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = UnifiedBatch::find($this->batchId);
        if ($batch) {
            $this->handleZipFailure($batch, $exception);
        }
    }
}
