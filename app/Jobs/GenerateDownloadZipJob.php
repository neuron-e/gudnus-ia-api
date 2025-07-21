<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Image;
use App\Models\Folder;
use App\Models\DownloadBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\Font;

class GenerateDownloadZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN PARA EVITAR REINTENTOS AUTOMÁTICOS
    public $timeout = 0;         // ⚡ SIN TIMEOUT - JOB LARGO
    public $tries = 1;           // ⚡ SOLO 1 INTENTO TOTAL
    public $maxExceptions = 1;   // ⚡ 1 EXCEPCIÓN MÁXIMA

    // ✅ EVITAR QUEUE TIMEOUT
    public $retryUntil;

    public function retryUntil()
    {
        return now()->addDays(1); // ⚡ 24 HORAS PARA COMPLETAR
    }

    public function backoff(): array
    {
        return [300]; // 5 minutos entre reintentos
    }

    public function __construct(
        public int $projectId,
        public string $type,
        public int $batchId
    ) {
        // ✅ CONFIGURAR MEMORIA Y TIMEOUT AGRESIVAMENTE
        ini_set('memory_limit', '6G');           // ⚡ MÁS MEMORIA
        ini_set('max_execution_time', 0);        // ⚡ SIN LÍMITE DE TIEMPO
        set_time_limit(0);                       // ⚡ SIN LÍMITE DE TIEMPO

        // ✅ CONFIGURAR TEMP DIRECTORY EXPLÍCITAMENTE
        $tempDir = sys_get_temp_dir();
        if (!is_writable($tempDir)) {
            ini_set('sys_temp_dir', storage_path('app/temp'));
        }
    }

    public function handle()
    {
        // ✅ RECONFIGURAR TODO AL INICIO
        ini_set('memory_limit', '6G');
        ini_set('max_execution_time', 0);
        set_time_limit(0);

        // ✅ CONFIGURAR OPTIMIZACIONES PHP
        ini_set('opcache.memory_consumption', '512');
        ini_set('opcache.max_accelerated_files', '30000');

        $startTime = microtime(true);

        Log::info("🚀 [FIXED-COMPRESS] GenerateDownloadZipJob iniciado", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'type' => $this->type,
            'memory_limit' => ini_get('memory_limit'),
            'timeout' => $this->timeout,
            'temp_dir' => sys_get_temp_dir(),
            'php_memory' => memory_get_usage(true) / 1024 / 1024 . 'MB'
        ]);

        $batch = DownloadBatch::find($this->batchId);
        if (!$batch) {
            Log::error("DownloadBatch {$this->batchId} no encontrado");
            return;
        }

        $project = Project::find($this->projectId);
        if (!$project) {
            $batch->update(['status' => 'failed', 'error' => 'Proyecto no encontrado']);
            return;
        }

        try {
            $batch->update([
                'status' => 'processing',
                'started_at' => now(),
                'processed_images' => 0
            ]);

            // ✅ VERIFICACIÓN PREVIA
            $this->performPreChecks();

            // ✅ OBTENER IMÁGENES CON EAGER LOADING
            $images = $this->getImagesForTypeOptimized($this->projectId, $this->type);

            if ($images->isEmpty()) {
                throw new \Exception("No hay imágenes del tipo '{$this->type}' para exportar");
            }

            $totalImages = $images->count();
            $batch->update(['total_images' => $totalImages]);

            Log::info("📊 [FIXED-COMPRESS] Procesando {$totalImages} imágenes tipo {$this->type}");

            // ✅ GENERACIÓN OPTIMIZADA CON COMPRESIÓN Y WASABI
            $wasabiPaths = $this->generateZipsWithMemoryControl($project, $images, $batch);

            // ✅ YA ESTÁN EN WASABI - GESTIÓN SIMPLIFICADA
            $finalPaths = $this->manageZipStorage($wasabiPaths, $project, $totalImages);

            $batch->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_images' => $totalImages,
                'file_paths' => $finalPaths,
                'expires_at' => now()->addDays(3)
            ]);

            $processingTime = round(microtime(true) - $startTime, 2);
            Log::info("✅ [FIXED-COMPRESS] ZIP generación completada", [
                'files_generated' => count($finalPaths),
                'total_images' => $totalImages,
                'processing_time' => $processingTime . 's',
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

        } catch (\Throwable $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ [FIXED-COMPRESS] Error generando ZIP", [
                'batch_id' => $this->batchId,
                'project_id' => $this->projectId,
                'type' => $this->type,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'processing_time' => $processingTime . 's',
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

            $batch->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

            // ✅ LIMPIAR EN CASO DE ERROR
            $this->cleanupTempFiles();
        }
    }

    /**
     * ✅ VERIFICACIONES PREVIAS
     */
    private function performPreChecks(): void
    {
        // ✅ Verificar espacio disponible
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);

        if (!$freeBytes) {
            Log::warning("⚠️ No se pudo verificar espacio disponible");
            return;
        }

        $freeGB = $freeBytes / 1024 / 1024 / 1024;
        Log::info("💾 Espacio libre: " . round($freeGB, 1) . "GB");

        // ✅ REQUERIMIENTOS MÁS CONSERVADORES
        $requiredGB = match($this->type) {
            'all' => 20,        // Más espacio para proyectos completos
            'analyzed' => 15,   // Imágenes analizadas necesitan más
            'processed' => 12,  // Imágenes procesadas
            'original' => 10,   // Solo originales
            default => 10
        };

        if ($freeGB < $requiredGB) {
            throw new \Exception("Espacio insuficiente: {$freeGB}GB libres. Se requieren al menos {$requiredGB}GB para tipo '{$this->type}'.");
        }

        // ✅ Verificar directorio temp
        $tempDir = storage_path('app/downloads');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        if (!is_writable($tempDir)) {
            throw new \Exception("Directorio downloads no escribible: {$tempDir}");
        }
    }

    /**
     * ✅ OBTENCIÓN OPTIMIZADA DE IMÁGENES
     */
    private function getImagesForTypeOptimized($projectId, $type)
    {
        $baseQuery = Image::with(['processedImage', 'folder'])
            ->whereHas('folder', fn($q) => $q->where('project_id', $projectId));

        return match($type) {
            'original' => $baseQuery
                ->whereNotNull('original_path')
                ->where('original_path', '!=', '')
                ->get(),

            'processed' => $baseQuery
                ->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                    ->where('corrected_path', '!=', '')
                )->get(),

            'analyzed' => $baseQuery
                ->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                    ->where('corrected_path', '!=', '')
                    ->whereNotNull('ai_response_json')
                    ->where('ai_response_json', '!=', '{}')
                    ->where('ai_response_json', '!=', '')
                )->get(),

            'all' => $baseQuery->get(),

            default => collect()
        };
    }

    /**
     * ✅ GENERACIÓN CON CONTROL AGRESIVO DE MEMORIA Y COMPRESIÓN
     */
    private function generateZipsWithMemoryControl($project, $images, $batch): array
    {
        $imageCount = $images->count();

        // ✅ CHUNKS OPTIMIZADOS CON COMPRESIÓN
        $maxImagesPerZip = match(true) {
            $imageCount > 8000 => 200,  // ✅ MÁS IMÁGENES con compresión (era 75)
            $imageCount > 5000 => 250,  // ✅ Más eficiente
            $imageCount > 3000 => 300,  // ✅ Chunks más grandes
            $imageCount > 1000 => 400,  // ✅ Menos ZIPs
            default => 500              // ✅ Máximo para proyectos pequeños
        };

        Log::info("📦 [OPTIMIZED-COMPRESS] Usando chunks de {$maxImagesPerZip} imágenes para {$imageCount} imágenes con compresión y subida a Wasabi");

        $imageChunks = $images->chunk($maxImagesPerZip);
        $wasabiPaths = []; // ✅ Cambiar a rutas de Wasabi
        $totalProcessed = 0;

        // ✅ CACHE DE FOLDERS
        $foldersCache = Folder::where('project_id', $project->id)->get()->keyBy('id');

        foreach ($imageChunks as $chunkIndex => $chunk) {
            try {
                // ✅ GESTIÓN AGRESIVA DE MEMORIA ENTRE CHUNKS
                if ($chunkIndex > 0) {
                    $this->aggressiveMemoryCleanup();
                }

                Log::info("📦 Procesando chunk " . ($chunkIndex + 1) . "/{$imageChunks->count()} ({$chunk->count()} imágenes)");

                $wasabiPath = $this->generateAndUploadZipChunk(
                    $project,
                    $chunk,
                    $this->type,
                    $chunkIndex + 1,
                    $imageChunks->count(),
                    $batch,
                    $totalProcessed,
                    $foldersCache
                );

                if ($wasabiPath) {
                    $wasabiPaths[] = $wasabiPath;
                    $totalProcessed += $chunk->count();
                    $batch->update(['processed_images' => $totalProcessed]);

                    Log::info("✅ Chunk " . ($chunkIndex + 1) . " completado. Total: {$totalProcessed}/{$imageCount}");
                }

            } catch (\Throwable $e) {
                Log::error("❌ Fallo CRÍTICO en chunk " . ($chunkIndex + 1) . ": " . $e->getMessage());

                // ✅ LIMPIEZA DE EMERGENCIA
                $this->emergencyCleanup();

                throw $e;
            }
        }

        return $wasabiPaths;
    }

    /**
     * ✅ GENERACIÓN Y SUBIDA DIRECTA A WASABI CON COMPRESIÓN
     */
    private function generateAndUploadZipChunk($project, $images, $type, $chunkNum, $totalChunks, $batch, $totalProcessedSoFar, $foldersCache)
    {
        $memoryBefore = memory_get_usage(true) / 1024 / 1024;
        Log::info("🧠 Memoria antes del chunk {$chunkNum}: {$memoryBefore}MB");

        $suffix = $totalChunks > 1 ? "_parte_{$chunkNum}" : '';
        $zipName = "export_{$type}_{$project->id}" . $suffix . "_" . now()->format('Ymd_His') . ".zip";

        // ✅ CREAR ZIP TEMPORAL PEQUEÑO
        $tempZipPath = sys_get_temp_dir() . '/' . $zipName;
        Log::info("📁 Creando ZIP temporal: {$tempZipPath}");

        // ✅ CONFIGURAR ZIP CON COMPRESIÓN ALTA
        $zip = new \ZipArchive;
        $openResult = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($openResult !== true) {
            $errorMsg = $this->getZipErrorMessage($openResult);
            throw new \Exception("No se pudo crear ZIP temporal: {$zipName}. Error: {$errorMsg}");
        }

        Log::info("✅ ZIP temporal abierto: {$zipName}");

        $wasabi = Storage::disk('wasabi');
        $root = Str::slug($project->name, '_');
        $processedInChunk = 0;
        $imageData = null;

        foreach ($images as $index => $img) {
            try {
                // ✅ LIMPIEZA DE MEMORIA CADA 25 IMÁGENES
                if (($index + 1) % 25 === 0) {
                    unset($imageData);
                    gc_collect_cycles();

                    $currentMemory = memory_get_usage(true) / 1024 / 1024;
                    if ($currentMemory > 2000) {
                        Log::warning("⚠️ Memoria alta en chunk {$chunkNum}, imagen {$index}: {$currentMemory}MB");
                        $this->aggressiveMemoryCleanup();
                    }
                }

                $folder = $foldersCache[$img->folder_id] ?? null;
                if (!$folder) {
                    Log::warning("⚠️ Folder no encontrado para imagen {$img->id}");
                    continue;
                }

                $folderPath = $this->getFolderPathForZipOptimized($folder, $foldersCache);
                $originalBaseName = $this->getOriginalImageNameOptimized($img);
                $addedAnyFile = false;

                // ✅ AGREGAR ARCHIVOS CON COMPRESIÓN INTELIGENTE
                if (in_array($type, ['original', 'all']) && $img->original_path && $wasabi->exists($img->original_path)) {
                    $originalExtension = $this->getOriginalExtension($img->original_path);
                    $filename = "{$originalBaseName}{$originalExtension}";

                    $imageData = $wasabi->get($img->original_path);

                    // ✅ VERIFICAR QUE SE DESCARGÓ CORRECTAMENTE
                    if ($imageData === null || $imageData === false) {
                        Log::warning("⚠️ No se pudo descargar imagen original: {$img->original_path}");
                        continue;
                    }

                    // ✅ COMPRIMIR IMAGEN SI ES NECESARIO
                    $compressedData = $this->compressImageIfNeeded($imageData, $originalExtension);

                    // ✅ VERIFICAR QUE LA COMPRESIÓN FUNCIONÓ
                    if (empty($compressedData)) {
                        Log::warning("⚠️ Compresión falló para imagen original: {$img->id}");
                        continue;
                    }

                    $zip->addFromString("{$root}/{$folderPath}/original/{$filename}", $compressedData);
                    unset($imageData, $compressedData);
                    $addedAnyFile = true;
                }

                if ($img->processedImage && $img->processedImage->corrected_path && $wasabi->exists($img->processedImage->corrected_path)) {
                    if (in_array($type, ['processed', 'all'])) {
                        $originalExtension = $this->getOriginalExtension($img->original_path);
                        $filename = "{$originalBaseName}_processed{$originalExtension}";

                        $imageData = $wasabi->get($img->processedImage->corrected_path);

                        // ✅ VERIFICAR DESCARGA
                        if ($imageData === null || $imageData === false) {
                            Log::warning("⚠️ No se pudo descargar imagen procesada: {$img->processedImage->corrected_path}");
                            continue;
                        }

                        // ✅ COMPRIMIR IMAGEN PROCESADA
                        $compressedData = $this->compressImageIfNeeded($imageData, $originalExtension);

                        // ✅ VERIFICAR COMPRESIÓN
                        if (empty($compressedData)) {
                            Log::warning("⚠️ Compresión falló para imagen procesada: {$img->id}");
                            continue;
                        }

                        $zip->addFromString("{$root}/{$folderPath}/processed/{$filename}", $compressedData);
                        unset($imageData, $compressedData);
                        $addedAnyFile = true;
                    }

                    // ✅ IMÁGENES ANALIZADAS CON COMPRESIÓN
                    if (in_array($type, ['analyzed', 'all']) && $img->processedImage->ai_response_json) {
                        $analyzedContent = $this->generateAnalyzedImageContentOptimized($img->processedImage);
                        if ($analyzedContent) {
                            $originalExtension = $this->getOriginalExtension($img->original_path);
                            $filename = "{$originalBaseName}_analyzed{$originalExtension}";

                            // ✅ COMPRIMIR IMAGEN ANALIZADA
                            $compressedAnalyzed = $this->compressImageIfNeeded($analyzedContent, '.jpg');

                            // ✅ VERIFICAR COMPRESIÓN ANALIZADA
                            if (empty($compressedAnalyzed)) {
                                Log::warning("⚠️ Compresión falló para imagen analizada: {$img->id}");
                                continue;
                            }

                            $zip->addFromString("{$root}/{$folderPath}/analyzed/{$filename}", $compressedAnalyzed);
                            unset($analyzedContent, $compressedAnalyzed);
                            $addedAnyFile = true;
                        }
                    }
                }

                if ($addedAnyFile) {
                    $processedInChunk++;
                }

                // ✅ ACTUALIZAR PROGRESO
                if (($index + 1) % 50 === 0) {
                    $currentTotal = $totalProcessedSoFar + $processedInChunk;
                    $batch->update(['processed_images' => $currentTotal]);
                    Log::info("📊 Progreso chunk {$chunkNum}: {$processedInChunk}/{$images->count()}");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error procesando imagen {$img->id}: " . $e->getMessage());
                continue;
            }
        }

        // ✅ VERIFICAR Y CERRAR ZIP
        if ($processedInChunk === 0) {
            $zip->close();
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }
            Log::warning("⚠️ Chunk {$chunkNum} vacío");
            return null;
        }

        Log::info("🔄 Cerrando ZIP temporal con {$processedInChunk} imágenes...");
        $numFiles = $zip->numFiles;
        $closeResult = $zip->close();

        if (!$closeResult) {
            throw new \Exception("❌ Error cerrando ZIP temporal chunk {$chunkNum}");
        }

        if (!file_exists($tempZipPath)) {
            throw new \Exception("❌ ZIP temporal no creado: {$tempZipPath}");
        }

        $tempSize = filesize($tempZipPath);
        Log::info("📏 ZIP temporal: " . round($tempSize/1024/1024, 1) . "MB con {$numFiles} archivos");

        // ✅ SUBIR A WASABI Y ELIMINAR LOCAL
        try {
            $wasabiPath = "downloads/project_{$project->id}/{$zipName}";

            Log::info("📤 Subiendo chunk {$chunkNum} a Wasabi...");

            $stream = fopen($tempZipPath, 'r');
            if (!$stream) {
                throw new \Exception("No se pudo abrir ZIP temporal para subida");
            }

            $uploadSuccess = $wasabi->writeStream($wasabiPath, $stream);
            fclose($stream);

            if (!$uploadSuccess) {
                throw new \Exception("Falló subida a Wasabi");
            }

            // ✅ VERIFICAR SUBIDA
            if (!$wasabi->exists($wasabiPath)) {
                throw new \Exception("ZIP no encontrado en Wasabi después de subida");
            }

            // ✅ ELIMINAR ARCHIVO TEMPORAL INMEDIATAMENTE
            unlink($tempZipPath);

            $memoryAfter = memory_get_usage(true) / 1024 / 1024;
            Log::info("✅ Chunk {$chunkNum} subido a Wasabi: {$wasabiPath}");
            Log::info("📊 {$processedInChunk} imágenes, " . round($tempSize/1024/1024, 1) . "MB, memoria: {$memoryAfter}MB");

            return $wasabiPath;

        } catch (\Exception $e) {
            // ✅ LIMPIAR EN CASO DE ERROR
            if (file_exists($tempZipPath)) {
                unlink($tempZipPath);
            }
            throw new \Exception("Error subiendo ZIP a Wasabi: " . $e->getMessage());
        }
    }

    /**
     * ✅ COMPRIMIR IMAGEN SI ES NECESARIO PARA REDUCIR TAMAÑO - VERSIÓN ARREGLADA
     */
    private function compressImageIfNeeded($imageData, $extension): string
    {
        try {
            // ✅ Verificar que imageData no esté vacío
            if (empty($imageData)) {
                Log::warning("⚠️ imageData vacío para compresión");
                return ''; // Retornar string vacío en lugar de null
            }

            // ✅ Solo comprimir JPG/JPEG para máximo ahorro
            if (!in_array(strtolower($extension), ['.jpg', '.jpeg'])) {
                return $imageData; // No comprimir PNG, etc.
            }

            // ✅ Verificar tamaño mínimo antes de procesar
            if (strlen($imageData) < 1024) { // Menos de 1KB
                Log::warning("⚠️ Imagen muy pequeña para compresión: " . strlen($imageData) . " bytes");
                return $imageData;
            }

            $manager = new ImageManager(new ImagickDriver());

            try {
                $image = $manager->read($imageData);
            } catch (\Exception $readException) {
                Log::warning("⚠️ No se pudo leer imagen para compresión: " . $readException->getMessage());
                return $imageData; // Retornar original si no se puede leer
            }

            // ✅ Verificar que la imagen se cargó correctamente
            if (!$image) {
                Log::warning("⚠️ No se pudo cargar imagen para compresión");
                return $imageData;
            }

            try {
                // ✅ REDUCIR CALIDAD AGRESIVAMENTE PARA DOWNLOADS
                $compressed = $image->toJpeg(70)->toString();
            } catch (\Exception $compressException) {
                Log::warning("⚠️ Error en toJpeg/toString: " . $compressException->getMessage());
                return $imageData; // Retornar original si falla compresión
            }

            // ✅ VERIFICAR QUE LA COMPRESIÓN FUNCIONÓ Y NO RETORNÓ NULL
            if ($compressed === null || $compressed === false || empty($compressed)) {
                Log::warning("⚠️ Compresión resultó null/vacía, usando original");
                return $imageData;
            }

            // ✅ Log del ahorro de espacio solo en debug (para no saturar logs)
            if (app()->environment('local')) {
                $originalSize = strlen($imageData);
                $compressedSize = strlen($compressed);
                $savedPercent = round((($originalSize - $compressedSize) / $originalSize) * 100, 1);

                if ($savedPercent > 5) {
                    Log::debug("📉 Compresión: {$savedPercent}% menos espacio");
                }
            }

            return $compressed;

        } catch (\Throwable $e) {
            Log::warning("⚠️ Error general comprimiendo imagen: " . $e->getMessage());

            // ✅ SIEMPRE retornar string - NUNCA null
            if (is_string($imageData) && !empty($imageData)) {
                return $imageData;
            }

            // ✅ Si imageData tampoco es válido, retornar string vacío
            Log::error("❌ imageData no válido, retornando string vacío");
            return '';
        }
    }

    /**
     * ✅ LIMPIEZA AGRESIVA DE MEMORIA
     */
    private function aggressiveMemoryCleanup(): void
    {
        // ✅ Limpiar todas las variables globales posibles
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // ✅ Forzar liberación de memoria
        if (function_exists('memory_get_usage')) {
            $memoryBefore = memory_get_usage(true) / 1024 / 1024;

            // Múltiples pasadas de garbage collection
            for ($i = 0; $i < 3; $i++) {
                gc_collect_cycles();
            }

            $memoryAfter = memory_get_usage(true) / 1024 / 1024;
            $freed = $memoryBefore - $memoryAfter;

            if ($freed > 1) {
                Log::info("🧹 Memoria liberada: " . round($freed, 1) . "MB");
            }
        }
    }

    /**
     * ✅ LIMPIEZA DE EMERGENCIA
     */
    private function emergencyCleanup(): void
    {
        Log::warning("🚨 Ejecutando limpieza de emergencia...");

        // ✅ Limpiar archivos temporales
        $tempDir = storage_path('app/temp_zips');
        if (is_dir($tempDir)) {
            $files = glob($tempDir . '/*');
            foreach ($files as $file) {
                if (is_file($file) && time() - filemtime($file) > 300) { // Más de 5 minutos
                    @unlink($file);
                }
            }
        }

        // ✅ Limpieza agresiva de memoria
        $this->aggressiveMemoryCleanup();

        // ✅ Reducir límites si es posible
        ini_set('memory_limit', '8G'); // Aumentar límite como último recurso
    }

    // ✅ RESTO DE MÉTODOS AUXILIARES

    private function getFolderPathForZipOptimized($folder, $foldersById): string
    {
        static $pathCache = [];
        $cacheKey = $folder->id;

        if (isset($pathCache[$cacheKey])) {
            return $pathCache[$cacheKey];
        }

        $path = [];
        $current = $folder;
        $maxDepth = 10;
        $depth = 0;

        while ($current && $depth < $maxDepth) {
            $name = str_replace(['/', '\\', '<', '>', ':', '"', '|', '?', '*'], '-', $current->name);
            $path[] = $name;
            $current = $foldersById[$current->parent_id] ?? null;
            $depth++;
        }

        $fullPath = implode('/', array_reverse($path));
        $pathCache[$cacheKey] = $fullPath;

        return $fullPath;
    }

    private function getOriginalImageNameOptimized($image): string
    {
        static $nameCache = [];
        $cacheKey = $image->id;

        if (isset($nameCache[$cacheKey])) {
            return $nameCache[$cacheKey];
        }

        $name = 'imagen_' . $image->id;

        if ($image->original_path) {
            $originalFilename = basename($image->original_path);
            $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);

            if ($baseName && $baseName !== 'image' && !empty($baseName)) {
                $name = $baseName;
            }
        }

        $nameCache[$cacheKey] = $name;
        return $name;
    }

    private function getOriginalExtension($originalPath): string
    {
        if (!$originalPath) {
            return '.jpg';
        }

        $extension = '.' . strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
        $validExtensions = ['.jpg', '.jpeg', '.png', '.bmp', '.tiff', '.webp'];

        return in_array($extension, $validExtensions) ? $extension : '.jpg';
    }

    private function generateAnalyzedImageContentOptimized($processedImage): ?string
    {
        static $analyzedCache = [];
        $cacheKey = md5($processedImage->corrected_path . $processedImage->ai_response_json);

        if (isset($analyzedCache[$cacheKey])) {
            return $analyzedCache[$cacheKey];
        }

        try {
            if (!$processedImage->ai_response_json) {
                return null;
            }

            $aiResponseJson = $processedImage->error_edits_json ?: $processedImage->ai_response_json;
            $correctedPath = $processedImage->corrected_path;
            $wasabi = Storage::disk('wasabi');

            if (!$wasabi->exists($correctedPath)) {
                Log::warning("⚠️ Imagen procesada no encontrada: {$correctedPath}");
                return null;
            }

            $imageData = $wasabi->get($correctedPath);
            $manager = new ImageManager(new ImagickDriver());
            $image = $manager->read($imageData);

            $parsed = json_decode($aiResponseJson, true);
            if (!$parsed) {
                return null;
            }

            $predictions = $parsed['final'] ?? ($parsed['predictions'] ?? []);
            $minProbability = $parsed['minProbability'] ?? 0.5;

            $errorColors = [
                'Intensidad' => '#FFA500',
                'Fingers' => '#00BFFF',
                'Black Edges' => '#333333',
                'Microgrietas' => '#FF0000',
            ];

            foreach ($predictions as $prediction) {
                if (isset($prediction['probability']) && $prediction['probability'] < $minProbability) {
                    continue;
                }

                $box = $prediction['boundingBox'];
                $left = (int) ($box['left'] * $image->width());
                $top = (int) ($box['top'] * $image->height());
                $width = (int) ($box['width'] * $image->width());
                $height = (int) ($box['height'] * $image->height());

                $tag = $prediction['tagName'] ?? '';
                $color = $errorColors[$tag] ?? '#FFFFFF';
                $label = sprintf('%s (%.1f%%)', $tag, $prediction['probability'] * 100);

                $rectangle = new Rectangle($width, $height);
                $rectangle->setBackgroundColor('transparent');
                $rectangle->setBorder($color, 2);
                $image->drawRectangle($left, $top, $rectangle);

                if (file_exists(resource_path('fonts/Inter_24pt-Regular.ttf'))) {
                    $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
                    $font->setColor('#FFFFFF');
                    $font->setSize(14);
                    $image->text($label, $left, $top - 12, $font);
                }
            }

            $content = $image->toJpeg(90)->toString();

            // ✅ GUARDAR EN CACHE CON LÍMITE
            if (count($analyzedCache) < 50) { // Reducir cache
                $analyzedCache[$cacheKey] = $content;
            }

            return $content;

        } catch (\Exception $e) {
            Log::warning("⚠️ Error generando imagen analizada: " . $e->getMessage());
            return null;
        }
    }

    private function manageZipStorage(array $wasabiPaths, $project, int $totalImages): array
    {
        // ✅ Ya están en Wasabi, solo retornar las rutas
        $totalChunks = count($wasabiPaths);
        $avgSizePerChunk = $totalImages > 0 ? round($totalImages / $totalChunks) : 0;

        Log::info("📊 Resumen final: {$totalChunks} ZIPs en Wasabi (~{$avgSizePerChunk} imágenes por ZIP)");

        return $wasabiPaths;
    }

    /**
     * ✅ OBTENER MENSAJE DE ERROR DETALLADO PARA ZIPARCHIVE
     */
    private function getZipErrorMessage($errorCode): string
    {
        return match($errorCode) {
            \ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            \ZipArchive::ER_RENAME => 'Renaming temporary file failed',
            \ZipArchive::ER_CLOSE => 'Closing zip archive failed',
            \ZipArchive::ER_SEEK => 'Seek error',
            \ZipArchive::ER_READ => 'Read error',
            \ZipArchive::ER_WRITE => 'Write error',
            \ZipArchive::ER_CRC => 'CRC error',
            \ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
            \ZipArchive::ER_NOENT => 'No such file',
            \ZipArchive::ER_EXISTS => 'File already exists',
            \ZipArchive::ER_OPEN => 'Can\'t open file',
            \ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
            \ZipArchive::ER_ZLIB => 'Zlib error',
            \ZipArchive::ER_MEMORY => 'Memory allocation failure',
            \ZipArchive::ER_CHANGED => 'Entry has been changed',
            \ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
            \ZipArchive::ER_EOF => 'Premature EOF',
            \ZipArchive::ER_INVAL => 'Invalid argument',
            \ZipArchive::ER_NOZIP => 'Not a zip archive',
            \ZipArchive::ER_INTERNAL => 'Internal error',
            \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            \ZipArchive::ER_REMOVE => 'Can\'t remove file',
            \ZipArchive::ER_DELETED => 'Entry has been deleted',
            default => "Error desconocido: {$errorCode}"
        };
    }

    /**
     * ✅ LIMPIEZA DE ARCHIVOS TEMPORALES MEJORADA
     */
    private function cleanupTempFiles(): void
    {
        try {
            // ✅ Limpiar archivos temporales de sistema
            $tempFiles = glob(sys_get_temp_dir() . '/export_*_' . $this->projectId . '_*.zip');
            foreach ($tempFiles as $file) {
                if (is_file($file) && time() - filemtime($file) > 3600) { // Más de 1 hora
                    @unlink($file);
                    Log::debug("🧹 Archivo temporal del sistema eliminado: " . basename($file));
                }
            }

            // ✅ Limpiar temp_zips si existe
            $tempDir = storage_path('app/temp_zips');
            if (is_dir($tempDir)) {
                $files = glob("{$tempDir}/*");
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                        Log::debug("🧹 Archivo temporal eliminado: " . basename($file));
                    }
                }
            }

            // ✅ Limpiar archivos de descarga locales antiguos
            $downloadsPath = storage_path('app/downloads');
            $pattern = "export_{$this->type}_{$this->projectId}_*";
            $files = glob("{$downloadsPath}/{$pattern}");

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < strtotime('-2 hours')) {
                    @unlink($file);
                    Log::debug("🧹 Archivo descarga local eliminado: " . basename($file));
                }
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ Error limpiando archivos temporales: " . $e->getMessage());
        }
    }

    /**
     * ✅ MANEJO DE FALLOS MEJORADO
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ [FIXED-COMPRESS] GenerateDownloadZipJob FAILED", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
            'line' => $exception->getLine(),
            'file' => $exception->getFile(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
            'timeout' => $this->timeout
        ]);

        $batch = DownloadBatch::find($this->batchId);
        if ($batch) {
            $batch->update([
                'status' => 'failed',
                'error' => 'Error: ' . $exception->getMessage() . ' (Línea: ' . $exception->getLine() . ')'
            ]);
        }

        // ✅ LIMPIEZA TOTAL EN CASO DE FALLO
        $this->emergencyCleanup();
        $this->cleanupTempFiles();
    }
}
