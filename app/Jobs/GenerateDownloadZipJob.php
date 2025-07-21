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

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA SERVIDOR POTENTE
    public $timeout = 18000;     // ✅ 5 horas (era 4) - proyectos muy grandes
    public $tries = 1;           // Solo 1 intento - control manual
    public $maxExceptions = 1;

    // ✅ Configurar memoria explícitamente
    public $memoryLimit = '4G';  // ✅ Más memoria (era 2G)

    public function backoff(): array
    {
        return [600]; // 10 minutos si hay retry
    }

    public function __construct(
        public int $projectId,
        public string $type,
        public int $batchId
    ) {
        // ✅ Configurar memoria al instanciar
        ini_set('memory_limit', $this->memoryLimit);
    }

    public function handle()
    {
        $startTime = microtime(true);

        Log::info("🚀 [OPTIMIZADO] GenerateDownloadZipJob iniciado", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'type' => $this->type,
            'memory_limit' => ini_get('memory_limit'),
            'timeout' => $this->timeout,
            'server_specs' => '8vCPU/32GB'
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

            // ✅ VERIFICACIÓN PREVIA OPTIMIZADA
            $this->performPreChecks();

            // ✅ OBTENER IMÁGENES CON EAGER LOADING
            $images = $this->getImagesForTypeOptimized($this->projectId, $this->type);

            if ($images->isEmpty()) {
                throw new \Exception("No hay imágenes del tipo '{$this->type}' para exportar");
            }

            $totalImages = $images->count();
            $batch->update(['total_images' => $totalImages]);

            Log::info("📊 [OPTIMIZADO] Procesando {$totalImages} imágenes tipo {$this->type}");

            // ✅ GENERACIÓN OPTIMIZADA DE ZIPS
            $localZipPaths = $this->generateZipsOptimized($project, $images, $batch);

            // ✅ GESTIÓN INTELIGENTE DE STORAGE
            $finalPaths = $this->manageZipStorage($localZipPaths, $project, $totalImages);

            $batch->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_images' => $totalImages,
                'file_paths' => $finalPaths,
                'expires_at' => now()->addDays(3)
            ]);

            $processingTime = round(microtime(true) - $startTime, 2);
            Log::info("✅ [OPTIMIZADO] ZIP generación completada", [
                'files_generated' => count($finalPaths),
                'total_images' => $totalImages,
                'processing_time' => $processingTime . 's',
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

        } catch (\Throwable $e) {
            $processingTime = round(microtime(true) - $startTime, 2);

            Log::error("❌ [OPTIMIZADO] Error generando ZIP", [
                'batch_id' => $this->batchId,
                'project_id' => $this->projectId,
                'type' => $this->type,
                'error' => $e->getMessage(),
                'processing_time' => $processingTime . 's',
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
            ]);

            $batch->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ✅ VERIFICACIONES PREVIAS OPTIMIZADAS
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

        // ✅ REQUERIMIENTOS DINÁMICOS SEGÚN TIPO
        $requiredGB = match($this->type) {
            'all' => 15,        // Proyectos completos necesitan mucho espacio
            'analyzed' => 12,   // Imágenes analizadas son pesadas
            'processed' => 10,  // Imágenes procesadas
            'original' => 8,    // Solo originales
            default => 8
        };

        if ($freeGB < $requiredGB) {
            throw new \Exception("Espacio insuficiente: {$freeGB}GB libres. Se requieren al menos {$requiredGB}GB para tipo '{$this->type}'.");
        }

        if ($freeGB < $requiredGB + 5) {
            Log::warning("⚠️ Espacio limitado: {$freeGB}GB libres para tipo '{$this->type}'");
        }
    }

    /**
     * ✅ OBTENCIÓN OPTIMIZADA DE IMÁGENES CON EAGER LOADING
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
     * ✅ GENERACIÓN OPTIMIZADA DE ZIPS
     */
    private function generateZipsOptimized($project, $images, $batch): array
    {
        $imageCount = $images->count();

        // ✅ CHUNKS DINÁMICOS OPTIMIZADOS PARA SERVIDOR POTENTE
        $maxImagesPerZip = match(true) {
            $imageCount > 3000 => 300,  // ✅ Proyectos masivos: chunks más grandes
            $imageCount > 2000 => 400,  // ✅ Proyectos muy grandes
            $imageCount > 1000 => 500,  // ✅ Proyectos grandes
            $imageCount > 500 => 600,   // ✅ Proyectos medianos
            default => 800              // ✅ Proyectos pequeños: chunks muy grandes
        };

        Log::info("📦 [OPTIMIZADO] Usando chunks de {$maxImagesPerZip} imágenes para {$imageCount} imágenes");

        $imageChunks = $images->chunk($maxImagesPerZip);
        $zipPaths = [];
        $totalProcessed = 0;

        // ✅ CACHE DE FOLDERS PARA EVITAR CONSULTAS REPETIDAS
        $foldersCache = Folder::where('project_id', $project->id)->get()->keyBy('id');

        foreach ($imageChunks as $chunkIndex => $chunk) {
            // ✅ GESTIÓN DE MEMORIA ENTRE CHUNKS
            if ($chunkIndex > 0) {
                gc_collect_cycles();
                $memoryMB = memory_get_usage(true) / 1024 / 1024;
                Log::info("🧹 Memoria entre chunks: {$memoryMB}MB");
            }

            Log::info("📦 Procesando chunk " . ($chunkIndex + 1) . "/{$imageChunks->count()} ({$chunk->count()} imágenes)");

            $zipPath = $this->generateZipForChunkOptimized(
                $project,
                $chunk,
                $this->type,
                $chunkIndex + 1,
                $imageChunks->count(),
                $batch,
                $totalProcessed,
                $foldersCache
            );

            if ($zipPath) {
                $zipPaths[] = $zipPath;
                $totalProcessed += $chunk->count();
                $batch->update(['processed_images' => $totalProcessed]);

                Log::info("✅ Chunk " . ($chunkIndex + 1) . " completado. Total: {$totalProcessed}/{$imageCount}");
            }
        }

        return $zipPaths;
    }

    /**
     * ✅ GENERACIÓN OPTIMIZADA DE ZIP POR CHUNK
     */
    private function generateZipForChunkOptimized($project, $images, $type, $chunkNum, $totalChunks, $batch, $totalProcessedSoFar, $foldersCache)
    {
        $suffix = $totalChunks > 1 ? "_parte_{$chunkNum}" : '';
        $zipName = "export_{$type}_{$project->id}" . $suffix . "_" . now()->format('Ymd_His') . ".zip";
        $zipPath = storage_path("app/downloads/{$zipName}");

        // ✅ CREAR DIRECTORIO SI NO EXISTE
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("No se pudo crear ZIP: {$zipName}");
        }

        $wasabi = Storage::disk('wasabi');
        $root = Str::slug($project->name, '_');
        $processedInChunk = 0;

        foreach ($images as $index => $img) {
            try {
                // ✅ USAR CACHE DE FOLDERS
                $folder = $foldersCache[$img->folder_id] ?? null;
                if (!$folder) {
                    Log::warning("⚠️ Folder no encontrado para imagen {$img->id}");
                    continue;
                }

                $folderPath = $this->getFolderPathForZipOptimized($folder, $foldersCache);
                $originalBaseName = $this->getOriginalImageNameOptimized($img);
                $addedAnyFile = false;

                // ✅ AGREGAR ARCHIVOS SEGÚN TIPO
                if (in_array($type, ['original', 'all']) && $img->original_path && $wasabi->exists($img->original_path)) {
                    $originalExtension = $this->getOriginalExtension($img->original_path);
                    $filename = "{$originalBaseName}{$originalExtension}";

                    $zip->addFromString("{$root}/{$folderPath}/original/{$filename}", $wasabi->get($img->original_path));
                    $addedAnyFile = true;
                }

                if ($img->processedImage && $img->processedImage->corrected_path && $wasabi->exists($img->processedImage->corrected_path)) {
                    if (in_array($type, ['processed', 'all'])) {
                        $originalExtension = $this->getOriginalExtension($img->original_path);
                        $filename = "{$originalBaseName}_processed{$originalExtension}";

                        $zip->addFromString("{$root}/{$folderPath}/processed/{$filename}", $wasabi->get($img->processedImage->corrected_path));
                        $addedAnyFile = true;
                    }

                    // ✅ IMÁGENES ANALIZADAS CON CACHE
                    if (in_array($type, ['analyzed', 'all']) && $img->processedImage->ai_response_json) {
                        $analyzedContent = $this->generateAnalyzedImageContentOptimized($img->processedImage);
                        if ($analyzedContent) {
                            $originalExtension = $this->getOriginalExtension($img->original_path);
                            $filename = "{$originalBaseName}_analyzed{$originalExtension}";

                            $zip->addFromString("{$root}/{$folderPath}/analyzed/{$filename}", $analyzedContent);
                            $addedAnyFile = true;
                        }
                    }
                }

                if ($addedAnyFile) {
                    $processedInChunk++;
                }

                // ✅ ACTUALIZAR PROGRESO CADA 100 IMÁGENES (más frecuente)
                if (($index + 1) % 100 === 0) {
                    $currentTotal = $totalProcessedSoFar + $processedInChunk;
                    $batch->update(['processed_images' => $currentTotal]);

                    Log::info("📊 Progreso chunk {$chunkNum}: {$processedInChunk}/{$images->count()} (Total: {$currentTotal}/{$batch->total_images})");
                }

            } catch (\Exception $e) {
                Log::warning("⚠️ Error procesando imagen {$img->id} en chunk {$chunkNum}: " . $e->getMessage());
                continue;
            }
        }

        $zip->close();

        Log::info("✅ ZIP chunk {$chunkNum}/{$totalChunks} generado: {$zipName} ({$processedInChunk} imágenes válidas)");

        return $zipPath;
    }

    /**
     * ✅ GESTIÓN INTELIGENTE DE STORAGE
     */
    private function manageZipStorage(array $localZipPaths, $project, int $totalImages): array
    {
        $wasabi = Storage::disk('wasabi');
        $finalPaths = [];
        $totalSizeMB = 0;

        // ✅ CALCULAR TAMAÑO TOTAL
        foreach ($localZipPaths as $localPath) {
            if (file_exists($localPath)) {
                $totalSizeMB += filesize($localPath) / 1024 / 1024;
            }
        }

        Log::info("📊 Tamaño total de ZIPs: " . round($totalSizeMB, 1) . "MB");

        // ✅ DECISIÓN INTELIGENTE: WASABI vs LOCAL
        $shouldMoveToWasabi = $this->shouldMoveToWasabi($totalSizeMB, $totalImages, $this->type);

        if ($shouldMoveToWasabi) {
            return $this->moveZipsToWasabiOptimized($localZipPaths, $project);
        } else {
            Log::info("📁 Manteniendo ZIPs en storage local");
            return $localZipPaths;
        }
    }

    /**
     * ✅ DECISIÓN INTELIGENTE WASABI vs LOCAL
     */
    private function shouldMoveToWasabi(float $totalSizeMB, int $totalImages, string $type): bool
    {
        // ✅ CRITERIOS MEJORADOS PARA SERVIDOR POTENTE
        return match(true) {
            $totalSizeMB > 500 => true,     // ✅ ZIPs muy grandes (era 100MB)
            $totalImages > 2000 => true,   // ✅ Proyectos masivos
            $type === 'all' => true,       // ✅ Exportaciones completas
            $totalSizeMB > 200 => true,    // ✅ ZIPs grandes
            default => false
        };
    }

    // ✅ MÉTODOS AUXILIARES OPTIMIZADOS

    private function getFolderPathForZipOptimized($folder, $foldersById): string
    {
        // ✅ USAR CACHE ESTÁTICO PARA RUTAS YA CALCULADAS
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
        // ✅ CACHE ESTÁTICO PARA NOMBRES YA CALCULADOS
        static $nameCache = [];
        $cacheKey = $image->id;

        if (isset($nameCache[$cacheKey])) {
            return $nameCache[$cacheKey];
        }

        $name = 'imagen_' . $image->id; // Default

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

    /**
     * ✅ GENERACIÓN OPTIMIZADA DE IMÁGENES ANALIZADAS
     */
    private function generateAnalyzedImageContentOptimized($processedImage): ?string
    {
        // ✅ CACHE ESTÁTICO PARA EVITAR REGENERAR
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

                // Rectángulo
                $rectangle = new Rectangle($width, $height);
                $rectangle->setBackgroundColor('transparent');
                $rectangle->setBorder($color, 2);
                $image->drawRectangle($left, $top, $rectangle);

                // Texto
                if (file_exists(resource_path('fonts/Inter_24pt-Regular.ttf'))) {
                    $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
                    $font->setColor('#FFFFFF');
                    $font->setSize(14);
                    $image->text($label, $left, $top - 12, $font);
                }
            }

            $content = $image->toJpeg(90)->toString();

            // ✅ GUARDAR EN CACHE CON LÍMITE
            if (count($analyzedCache) < 100) {
                $analyzedCache[$cacheKey] = $content;
            }

            return $content;

        } catch (\Exception $e) {
            Log::warning("⚠️ Error generando imagen analizada: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ MOVIMIENTO OPTIMIZADO A WASABI
     */
    private function moveZipsToWasabiOptimized(array $localZipPaths, $project): array
    {
        Log::info("📤 Moviendo ZIPs a Wasabi para liberar espacio local...");

        $wasabi = Storage::disk('wasabi');
        $finalPaths = [];
        $movedCount = 0;
        $totalFiles = count($localZipPaths);

        foreach ($localZipPaths as $index => $localPath) {
            if (!file_exists($localPath)) {
                Log::warning("⚠️ Archivo local no encontrado: {$localPath}");
                continue;
            }

            try {
                $fileName = basename($localPath);
                $wasabiPath = "downloads/project_{$project->id}/{$fileName}";

                // ✅ SUBIDA OPTIMIZADA CON STREAM
                $stream = fopen($localPath, 'r');
                if (!$stream) {
                    throw new \Exception("No se pudo abrir el archivo local: {$localPath}");
                }

                $success = $wasabi->writeStream($wasabiPath, $stream);
                fclose($stream);

                if (!$success) {
                    throw new \Exception("Falló la subida a Wasabi");
                }

                // ✅ VERIFICACIÓN RÁPIDA
                if (!$wasabi->exists($wasabiPath)) {
                    throw new \Exception("Archivo no encontrado en Wasabi después de la subida");
                }

                $localSizeMB = filesize($localPath) / 1024 / 1024;

                // ✅ ELIMINAR ARCHIVO LOCAL INMEDIATAMENTE
                unlink($localPath);
                $finalPaths[] = $wasabiPath;
                $movedCount++;

                Log::info("✅ ZIP movido a Wasabi: {$fileName} (" . round($localSizeMB, 1) . "MB) [{$movedCount}/{$totalFiles}]");

            } catch (\Exception $e) {
                Log::error("❌ Error moviendo ZIP a Wasabi: " . $e->getMessage());
                $finalPaths[] = $localPath;

                // ✅ Limpiar archivo parcial en Wasabi si existe
                if (isset($wasabiPath) && $wasabi->exists($wasabiPath)) {
                    $wasabi->delete($wasabiPath);
                }
            }
        }

        Log::info("📤 Resumen movimiento: {$movedCount}/{$totalFiles} ZIPs movidos a Wasabi");
        return $finalPaths;
    }

    /**
     * ✅ MANEJO DE FALLOS OPTIMIZADO
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("❌ GenerateDownloadZipJob FAILED", [
            'batch_id' => $this->batchId,
            'project_id' => $this->projectId,
            'type' => $this->type,
            'error' => $exception->getMessage(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
            'timeout' => $this->timeout,
            'server_specs' => '8vCPU/32GB'
        ]);

        $batch = DownloadBatch::find($this->batchId);
        if ($batch) {
            $batch->update([
                'status' => 'failed',
                'error' => $exception->getMessage()
            ]);
        }

        // ✅ LIMPIEZA DE ARCHIVOS TEMPORALES
        $this->cleanupTempFiles();
    }

    /**
     * ✅ LIMPIEZA DE ARCHIVOS TEMPORALES
     */
    private function cleanupTempFiles(): void
    {
        try {
            $downloadsPath = storage_path('app/downloads');
            $pattern = "export_{$this->type}_{$this->projectId}_*";
            $files = glob("{$downloadsPath}/{$pattern}");

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) > strtotime('-1 hour')) {
                    @unlink($file);
                    Log::debug("🧹 Archivo temporal eliminado: " . basename($file));
                }
            }
        } catch (\Exception $e) {
            Log::warning("⚠️ Error limpiando archivos temporales: " . $e->getMessage());
        }
    }
}
