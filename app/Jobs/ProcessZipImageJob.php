<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Services\ImageProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessZipImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA SERVIDOR POTENTE
    public $timeout = 900;      // ✅ 15 minutos por imagen (era 10)
    public $tries = 3;          // ✅ Más reintentos (era 2)
    public $maxExceptions = 3;

    // ✅ Backoff optimizado
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    public function __construct(
        public int $projectId,
        public array $asignacion,
        public string $tempPath,
        public int $batchId
    ) {}

    public function handle()
    {
        $startTime = microtime(true);
        $attemptNumber = $this->attempts();

        Log::info("🚀 [INTENTO {$attemptNumber}] ProcessZipImageJob iniciado", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'imagen' => $this->asignacion['imagen'] ?? 'N/A',
            'modulo' => $this->asignacion['modulo'] ?? 'N/A',
            'temp_path' => $this->tempPath,
            'memory_start' => memory_get_usage(true) / 1024 / 1024 . 'MB'
        ]);

        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch no encontrado: {$this->batchId}");
            return;
        }

        // ✅ VERIFICAR SI EL BATCH FUE CANCELADO
        if (in_array($batch->status, ['failed', 'cancelled'])) {
            Log::info("ℹ️ Job cancelado - batch en estado: {$batch->status}");
            return;
        }

        try {
            // ✅ VALIDACIÓN PREVIA OPTIMIZADA
            $validation = $this->validateAssignment();
            if (!$validation['valid']) {
                $this->incrementError($batch, $validation['error']);
                return;
            }

            ['nombreImagen' => $nombreImagen, 'moduloPath' => $moduloPath] = $validation;

            // ✅ BUSCAR ARCHIVO EXTRAÍDO CON MEJOR ALGORITMO
            $extractedFile = $this->findExtractedFile($nombreImagen);
            if (!$extractedFile) {
                $this->incrementError($batch, "Archivo no encontrado: {$nombreImagen}");
                return;
            }

            // ✅ BUSCAR FOLDER CON CACHE
            $folder = $this->findFolder($moduloPath);
            if (!$folder) {
                $this->incrementError($batch, "Módulo no encontrado: {$moduloPath}");
                return;
            }

            // ✅ PROCESAR IMAGEN
            $success = $this->processImageForFolder($folder, $extractedFile, $nombreImagen, $batch);

            $processingTime = round(microtime(true) - $startTime, 2);

            if ($success) {
                Log::info("✅ [ÉXITO] Imagen procesada correctamente", [
                    'image_name' => $nombreImagen,
                    'folder_id' => $folder->id,
                    'processing_time' => $processingTime . 's',
                    'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
                ]);
            } else {
                $this->incrementError($batch, "Error procesando {$nombreImagen}");
            }

        } catch (\Throwable $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ [ERROR] ProcessZipImageJob falló", [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage(),
                'processing_time' => $processingTime . 's',
                'attempt' => $attemptNumber,
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

            $this->incrementError($batch, "Error procesando: " . $e->getMessage());
        }
    }

    /**
     * ✅ VALIDACIÓN OPTIMIZADA DE ASIGNACIÓN
     */
    private function validateAssignment(): array
    {
        if (!isset($this->asignacion['imagen']) || !isset($this->asignacion['modulo'])) {
            return [
                'valid' => false,
                'error' => 'Asignación incompleta: faltan campos imagen o modulo'
            ];
        }

        $nombreImagen = basename($this->asignacion['imagen']);
        $moduloPath = trim($this->asignacion['modulo']);

        if (empty($nombreImagen) || empty($moduloPath)) {
            return [
                'valid' => false,
                'error' => 'Nombre de imagen o ruta de módulo vacíos'
            ];
        }

        // ✅ VALIDAR EXTENSIÓN
        $extension = strtolower(pathinfo($nombreImagen, PATHINFO_EXTENSION));
        $validExtensions = ['jpg', 'jpeg', 'png', 'bmp', 'tiff', 'webp'];

        if (!in_array($extension, $validExtensions)) {
            return [
                'valid' => false,
                'error' => "Extensión no válida: {$extension}"
            ];
        }

        return [
            'valid' => true,
            'nombreImagen' => $nombreImagen,
            'moduloPath' => $moduloPath,
            'extension' => $extension
        ];
    }

    /**
     * ✅ BÚSQUEDA OPTIMIZADA DE ARCHIVO EXTRAÍDO
     */
    private function findExtractedFile(string $nombreImagen): ?string
    {
        if (!is_dir($this->tempPath)) {
            Log::error("❌ Directorio temporal no existe: {$this->tempPath}");
            return null;
        }

        // ✅ BÚSQUEDA RECURSIVA OPTIMIZADA
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $filename = $file->getFilename();

                // ✅ COMPARACIÓN CASE-INSENSITIVE
                if (strtolower($filename) === strtolower($nombreImagen)) {
                    $filePath = $file->getPathname();

                    // ✅ VERIFICAR QUE EL ARCHIVO ES ACCESIBLE
                    if (is_readable($filePath) && filesize($filePath) > 0) {
                        Log::debug("✅ Archivo encontrado: {$filePath}");
                        return $filePath;
                    }
                }
            }
        }

        // ✅ BÚSQUEDA ALTERNATIVA SIN EXTENSIÓN
        $nameWithoutExt = pathinfo($nombreImagen, PATHINFO_FILENAME);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileNameWithoutExt = pathinfo($file->getFilename(), PATHINFO_FILENAME);

                if (strtolower($fileNameWithoutExt) === strtolower($nameWithoutExt)) {
                    $filePath = $file->getPathname();

                    if (is_readable($filePath) && filesize($filePath) > 0) {
                        Log::debug("✅ Archivo encontrado por nombre base: {$filePath}");
                        return $filePath;
                    }
                }
            }
        }

        Log::warning("⚠️ Archivo no encontrado: {$nombreImagen} en {$this->tempPath}");
        return null;
    }

    /**
     * ✅ BÚSQUEDA OPTIMIZADA DE FOLDER CON CACHE
     */
    private function findFolder(string $moduloPath): ?Folder
    {
        // ✅ CACHE ESTÁTICO PARA BÚSQUEDAS REPETIDAS
        static $folderCache = [];
        $cacheKey = "{$this->projectId}:{$moduloPath}";

        if (isset($folderCache[$cacheKey])) {
            return $folderCache[$cacheKey];
        }

        // ✅ BÚSQUEDA OPTIMIZADA POR FULL_PATH
        $folder = Folder::where('project_id', $this->projectId)
            ->where('full_path', $moduloPath)
            ->first();

        if (!$folder) {
            // ✅ BÚSQUEDA ALTERNATIVA POR NOMBRE
            $folder = Folder::where('project_id', $this->projectId)
                ->where('name', $moduloPath)
                ->first();
        }

        // ✅ GUARDAR EN CACHE
        $folderCache[$cacheKey] = $folder;

        if ($folder) {
            Log::debug("✅ Folder encontrado: ID {$folder->id}, Nombre: {$folder->name}");
        } else {
            Log::warning("⚠️ Folder no encontrado: {$moduloPath}");
        }

        return $folder;
    }

    /**
     * ✅ PROCESAMIENTO OPTIMIZADO DE IMAGEN
     */
    private function processImageForFolder(Folder $folder, string $extractedFile, string $nombreImagen, ImageBatch $batch): bool
    {
        try {
            // ✅ LIMPIEZA OPTIMIZADA DE IMÁGENES EXISTENTES
            $this->cleanupExistingImages($folder);

            // ✅ SUBIDA OPTIMIZADA A WASABI
            $wasabiPath = $this->uploadToWasabi($extractedFile, $nombreImagen);
            if (!$wasabiPath) {
                return false;
            }

            // ✅ CREACIÓN DE IMAGEN EN BD
            $image = $this->createImageRecord($folder, $wasabiPath);
            if (!$image) {
                return false;
            }

            // ✅ PROCESAMIENTO CON SERVICE
            $processed = $this->processWithService($image);
            if (!$processed) {
                return false;
            }

            // ✅ VERIFICACIÓN DE ÉXITO Y ACTUALIZACIÓN DE BATCH
            $success = $this->validateProcessingSuccess($processed, $image);
            if ($success) {
                $this->updateBatchProgress($batch);
            }

            return $success;

        } catch (\Throwable $e) {
            Log::error("❌ Error procesando imagen {$nombreImagen}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ LIMPIEZA OPTIMIZADA DE IMÁGENES EXISTENTES
     */
    private function cleanupExistingImages(Folder $folder): void
    {
        $existingImages = $folder->images;
        if ($existingImages->isEmpty()) {
            return;
        }

        Log::debug("🧹 Limpiando {$existingImages->count()} imágenes existentes del folder {$folder->id}");

        foreach ($existingImages as $existing) {
            try {
                // ✅ ELIMINAR DE WASABI
                if ($existing->original_path && Storage::disk('wasabi')->exists($existing->original_path)) {
                    Storage::disk('wasabi')->delete($existing->original_path);
                }

                // ✅ ELIMINAR RELACIONES
                $existing->processedImage?->delete();
                $existing->analysisResult?->delete();
                $existing->delete();

            } catch (\Exception $e) {
                Log::warning("⚠️ Error limpiando imagen existente {$existing->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * ✅ SUBIDA OPTIMIZADA A WASABI
     */
    private function uploadToWasabi(string $extractedFile, string $nombreImagen): ?string
    {
        try {
            $imageContent = file_get_contents($extractedFile);
            if ($imageContent === false) {
                throw new \Exception("No se pudo leer el archivo");
            }

            $wasabiPath = "projects/{$this->projectId}/images/{$nombreImagen}";

            Log::debug("📤 Subiendo a Wasabi", [
                'wasabi_path' => $wasabiPath,
                'image_size' => strlen($imageContent) / 1024 . 'KB'
            ]);

            $success = Storage::disk('wasabi')->put($wasabiPath, $imageContent);
            if (!$success) {
                throw new \Exception("Falló la subida a Wasabi");
            }

            // ✅ VERIFICAR QUE SE SUBIÓ CORRECTAMENTE
            if (!Storage::disk('wasabi')->exists($wasabiPath)) {
                throw new \Exception("Archivo no encontrado en Wasabi después de la subida");
            }

            return $wasabiPath;

        } catch (\Exception $e) {
            Log::error("❌ Error subiendo a Wasabi: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ CREACIÓN OPTIMIZADA DE REGISTRO DE IMAGEN
     */
    private function createImageRecord(Folder $folder, string $wasabiPath): ?Image
    {
        try {
            $image = Image::create([
                'folder_id' => $folder->id,
                'original_path' => $wasabiPath,
                'status' => 'uploaded',
                'is_counted' => false,
            ]);

            Log::debug("✅ Imagen creada en BD: ID {$image->id}");
            return $image;

        } catch (\Exception $e) {
            Log::error("❌ Error creando imagen en BD: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ PROCESAMIENTO CON SERVICE OPTIMIZADO
     */
    private function processWithService(Image $image): ?\App\Models\Image
    {
        try {
            $service = app(ImageProcessingService::class);
            $processed = $service->process($image, $this->batchId);

            if (!$processed) {
                Log::warning("⚠️ ImageProcessingService retornó null para imagen {$image->id}");
                return null;
            }

            Log::debug("✅ Imagen procesada con service", [
                'image_id' => $image->id,
                'status' => $processed->status ?? 'unknown',
                'has_processed_image' => $processed->processedImage !== null
            ]);

            return $processed;

        } catch (\Exception $e) {
            Log::error("❌ Error en ImageProcessingService: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ VALIDACIÓN DE ÉXITO DE PROCESAMIENTO
     */
    private function validateProcessingSuccess(\App\Models\Image $processed, Image $originalImage): bool
    {
        // ✅ VERIFICAR MÚLTIPLES CONDICIONES DE ÉXITO
        $hasProcessedImage = $processed->processedImage && $processed->processedImage->corrected_path;
        $statusIsProcessed = $processed->status === 'processed';
        $statusIsNotError = $processed->status !== 'error';

        $isSuccessful = $hasProcessedImage || $statusIsProcessed || $statusIsNotError;

        if ($isSuccessful) {
            Log::debug("✅ Procesamiento exitoso", [
                'image_id' => $processed->id,
                'has_corrected_path' => $hasProcessedImage,
                'status' => $processed->status,
                'processing_method' => $processed->analysisResult?->processing_method ?? 'unknown'
            ]);
        } else {
            Log::warning("⚠️ Procesamiento no exitoso", [
                'image_id' => $processed->id,
                'status' => $processed->status,
                'has_processed_image' => $processed->processedImage !== null,
                'has_corrected_path' => $hasProcessedImage
            ]);
        }

        return $isSuccessful;
    }

    /**
     * ✅ ACTUALIZACIÓN THREAD-SAFE DEL BATCH
     */
    private function updateBatchProgress(ImageBatch $batch): void
    {
        try {
            \DB::transaction(function() use ($batch) {
                // ✅ MARCAR IMAGEN COMO CONTADA
                $batch->increment('processed');
                $batch->touch();
            });

            $currentProgress = $batch->fresh();
            $progressPercent = $currentProgress->total > 0
                ? round(($currentProgress->processed / $currentProgress->total) * 100, 1)
                : 0;

            // ✅ LOG DE PROGRESO CADA 50 IMÁGENES
            if ($currentProgress->processed % 50 === 0) {
                Log::info("📊 Progreso batch {$batch->id}: {$currentProgress->processed}/{$currentProgress->total} ({$progressPercent}%)");
            }

        } catch (\Exception $e) {
            Log::warning("⚠️ Error actualizando progreso batch: " . $e->getMessage());
        }
    }

    /**
     * ✅ MANEJO OPTIMIZADO DE ERRORES
     */
    private function incrementError(ImageBatch $batch, string $message): void
    {
        Log::error("❌ Error en ProcessZipImageJob: {$message}", [
            'batch_id' => $this->batchId,
            'attempt' => $this->attempts()
        ]);

        try {
            \DB::transaction(function() use ($batch, $message) {
                $batch->increment('errors');

                // ✅ MANTENER HISTÓRICO DE ERRORES LIMITADO
                $errors = $batch->error_messages ?? [];
                $errors[] = [
                    'message' => $message,
                    'timestamp' => now()->toISOString(),
                    'attempt' => $this->attempts()
                ];

                // ✅ MANTENER SOLO LOS ÚLTIMOS 100 ERRORES
                $batch->update([
                    'error_messages' => array_slice($errors, -100),
                    'updated_at' => now()
                ]);
            });

        } catch (\Exception $e) {
            Log::error("Error actualizando errores en batch: " . $e->getMessage());
        }
    }

    /**
     * ✅ MANEJO DE FALLOS DEFINITIVOS
     */
    public function failed(\Throwable $e): void
    {
        Log::error("❌ ProcessZipImageJob FAILED definitivamente", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'image' => $this->asignacion['imagen'] ?? 'unknown',
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
        ]);

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $this->incrementError($batch, "Job failed definitivamente: " . $e->getMessage());

            // ✅ VERIFICAR SI EL BATCH TIENE DEMASIADOS ERRORES
            $errorRate = $batch->errors / max($batch->total, 1);
            if ($errorRate > 0.5) { // 50% de error máximo
                Log::critical("💀 Batch {$batch->id} con demasiados errores ({$errorRate}%), marcando como fallido");
                $batch->update(['status' => 'failed']);
            }
        }
    }
}
