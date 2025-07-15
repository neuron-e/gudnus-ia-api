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

    public $timeout = 7200; // 2 horas
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public string $type, // original, processed, analyzed, all
        public int $batchId
    ) {}

    public function handle()
    {
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

        Log::info("🚀 Iniciando generación ZIP {$this->type} para proyecto {$this->projectId}");

        try {
            $batch->update([
                'status' => 'processing',
                'started_at' => now(),
                'processed_images' => 0
            ]);

            // ✅ Obtener imágenes según el tipo específico
            $images = $this->getImagesForType($this->projectId, $this->type);

            if ($images->isEmpty()) {
                throw new \Exception("No hay imágenes del tipo '{$this->type}' para exportar");
            }

            // ✅ Actualizar total real
            $batch->update(['total_images' => $images->count()]);

            Log::info("📊 Procesando {$images->count()} imágenes tipo {$this->type}");

            // ✅ Generar ZIPs (sin cambios en la lógica de generación)
            $localZipPaths = $this->generateZips($project, $images, $batch);

            // 🆕 NUEVA FUNCIONALIDAD: Mover archivos grandes a Wasabi
            $finalPaths = $this->moveZipsToWasabiIfNeeded($localZipPaths, $project);

            // ✅ Actualizar batch con resultados finales
            $batch->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_images' => $images->count(),
                'file_paths' => $finalPaths, // ✅ Pueden ser rutas locales o de Wasabi
                'expires_at' => now()->addDays(3)
            ]);

            Log::info("✅ ZIP generación completada: " . count($finalPaths) . " archivos");

        } catch (\Throwable $e) {
            Log::error("❌ Error generando ZIP: " . $e->getMessage(), [
                'batch_id' => $this->batchId,
                'project_id' => $this->projectId,
                'type' => $this->type,
                'trace' => $e->getTraceAsString()
            ]);

            $batch->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🆕 NUEVO: Verificar espacio disponible antes de generar
     */
    private function checkAvailableSpace($estimatedSizeMB): void
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);

        if (!$freeBytes) {
            Log::warning("⚠️ No se pudo verificar espacio disponible");
            return;
        }

        $freeGB = $freeBytes / 1024 / 1024 / 1024;
        $requiredGB = $estimatedSizeMB / 1024;

        Log::info("💾 Espacio: {$freeGB}GB libres, {$requiredGB}GB requeridos");

        if ($freeGB < ($requiredGB + 2)) { // +2GB de buffer
            throw new \Exception("Espacio insuficiente: {$freeGB}GB libres, {$requiredGB}GB requeridos");
        }

        if ($freeGB < 5) {
            Log::warning("⚠️ Espacio bajo: solo {$freeGB}GB libres");
        }
    }

    private function generateZips($project, $images, $batch): array
    {
        // ✅ Estrategia: Múltiples ZIPs si es necesario
        $maxImagesPerZip = 500;
        $imageChunks = $images->chunk($maxImagesPerZip);
        $zipPaths = [];
        $totalProcessed = 0;

        foreach ($imageChunks as $chunkIndex => $chunk) {
            Log::info("📦 Procesando chunk " . ($chunkIndex + 1) . "/{$imageChunks->count()} ({$chunk->count()} imágenes)");

            $zipPath = $this->generateZipForChunk(
                $project,
                $chunk,
                $this->type,
                $chunkIndex + 1,
                $imageChunks->count(),
                $batch,
                $totalProcessed
            );

            if ($zipPath) {
                $zipPaths[] = $zipPath;
                $totalProcessed += $chunk->count();

                // ✅ Actualizar progreso después de cada chunk
                $batch->update(['processed_images' => $totalProcessed]);
                $sumChunks = $chunkIndex + 1;
                Log::info("✅ Chunk {$sumChunks} completado. Total procesado: {$totalProcessed}/{$images->count()}");
            }
        }

        return $zipPaths;
    }

    /**
     * 🆕 NUEVO: Mover ZIPs grandes a Wasabi para liberar espacio local
     */
    private function moveZipsToWasabiIfNeeded(array $localZipPaths, $project): array
    {
        $wasabi = Storage::disk('wasabi');
        $finalPaths = [];
        $totalSizeMB = 0;

        // ✅ Calcular tamaño total de los ZIPs
        foreach ($localZipPaths as $localPath) {
            if (file_exists($localPath)) {
                $totalSizeMB += filesize($localPath) / 1024 / 1024;
            }
        }

        Log::info("📊 Tamaño total de ZIPs: " . round($totalSizeMB, 1) . "MB");

        // ✅ Si el tamaño total es > 100MB, mover a Wasabi
        $shouldMoveToWasabi = $totalSizeMB > 100;

        if ($shouldMoveToWasabi) {
            Log::info("📤 Moviendo ZIPs grandes a Wasabi para liberar espacio local...");

            foreach ($localZipPaths as $localPath) {
                if (!file_exists($localPath)) {
                    Log::warning("⚠️ Archivo local no encontrado: {$localPath}");
                    continue;
                }

                try {
                    // ✅ Generar ruta en Wasabi
                    $fileName = basename($localPath);
                    $wasabiPath = "downloads/project_{$project->id}/{$fileName}";

                    // ✅ Subir a Wasabi usando stream para archivos grandes
                    $stream = fopen($localPath, 'r');
                    if (!$stream) {
                        throw new \Exception("No se pudo abrir el archivo local: {$localPath}");
                    }

                    $success = $wasabi->writeStream($wasabiPath, $stream);
                    fclose($stream);

                    if (!$success) {
                        throw new \Exception("Falló la subida a Wasabi");
                    }

                    // ✅ Verificar que se subió correctamente
                    if (!$wasabi->exists($wasabiPath)) {
                        throw new \Exception("Archivo no encontrado en Wasabi después de la subida");
                    }

                    $localSizeMB = filesize($localPath) / 1024 / 1024;
                    $wasabiSizeMB = $wasabi->size($wasabiPath) / 1024 / 1024;

                    if (abs($localSizeMB - $wasabiSizeMB) > 1) { // Tolerancia de 1MB
                        throw new \Exception("Tamaños no coinciden: local={$localSizeMB}MB, wasabi={$wasabiSizeMB}MB");
                    }

                    // ✅ Eliminar archivo local después de verificar
                    unlink($localPath);

                    // ✅ Usar ruta de Wasabi
                    $finalPaths[] = $wasabiPath;

                    Log::info("✅ ZIP movido a Wasabi: {$fileName} (" . round($localSizeMB, 1) . "MB)");

                } catch (\Exception $e) {
                    Log::error("❌ Error moviendo ZIP a Wasabi: " . $e->getMessage());

                    // ✅ En caso de error, mantener archivo local
                    $finalPaths[] = $localPath;

                    // ✅ Limpiar archivo parcial en Wasabi si existe
                    if (isset($wasabiPath) && $wasabi->exists($wasabiPath)) {
                        $wasabi->delete($wasabiPath);
                    }
                }
            }
            $countPaths = count($localZipPaths);
            $movedCount = collect($finalPaths)->filter(fn($path) => str_starts_with($path, 'downloads/'))->count();
            Log::info("📤 Resumen: {$movedCount}/{$countPaths} ZIPs movidos a Wasabi");

        } else {
            Log::info("📁 ZIPs pequeños (<100MB), manteniéndolos en storage local");
            $finalPaths = $localZipPaths;
        }

        return $finalPaths;
    }


    /**
     * ✅ Obtener imágenes específicas según el tipo
     */
    private function getImagesForType($projectId, $type)
    {
        $query = Image::with(['processedImage', 'folder'])
            ->whereHas('folder', fn($q) => $q->where('project_id', $projectId));

        switch ($type) {
            case 'original':
                return $query->whereNotNull('original_path')->get();

            case 'processed':
                return $query->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                )->get();

            case 'analyzed':
                return $query->whereHas('processedImage', fn($q) =>
                $q->whereNotNull('corrected_path')
                    ->whereNotNull('ai_response_json')
                )->get();

            case 'all':
                return $query->get();

            default:
                return collect();
        }
    }

    private function generateZipForChunk($project, $images, $type, $chunkNum, $totalChunks, $batch, $totalProcessedSoFar)
    {
        $suffix = $totalChunks > 1 ? "_parte_{$chunkNum}" : '';
        $zipName = "export_{$type}_{$project->id}" . $suffix . "_" . now()->format('Ymd_His') . ".zip";
        $zipPath = storage_path("app/downloads/{$zipName}");

        // Crear directorio si no existe
        if (!is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception("No se pudo crear ZIP: {$zipName}");
        }

        $wasabi = Storage::disk('wasabi');
        $folders = Folder::where('project_id', $project->id)->get()->keyBy('id');
        $root = Str::slug($project->name, '_');
        $processedInChunk = 0;

        foreach ($images as $index => $img) {
            try {
                $folderPath = $this->getFolderPathForZip($img->folder, $folders);

                // ✅ SIMPLIFICADO: Obtener nombre original solo desde original_path
                $originalBaseName = $this->getOriginalImageName($img);

                $addedAnyFile = false;

                // ✅ Agregar archivos según el tipo solicitado
                if (in_array($type, ['original', 'all']) && $img->original_path && $wasabi->exists($img->original_path)) {
                    // ✅ MANTENER EXTENSIÓN ORIGINAL
                    $originalExtension = $this->getOriginalExtension($img->original_path);
                    $filename = "{$originalBaseName}{$originalExtension}";

                    $zip->addFromString("{$root}/{$folderPath}/original/{$filename}", $wasabi->get($img->original_path));
                    $addedAnyFile = true;
                }

                if ($img->processedImage && $img->processedImage->corrected_path && $wasabi->exists($img->processedImage->corrected_path)) {
                    if (in_array($type, ['processed', 'all'])) {
                        // ✅ USAR NOMBRE ORIGINAL + _processed + EXTENSIÓN ORIGINAL
                        $originalExtension = $this->getOriginalExtension($img->original_path);
                        $filename = "{$originalBaseName}_processed{$originalExtension}";

                        $zip->addFromString("{$root}/{$folderPath}/processed/{$filename}", $wasabi->get($img->processedImage->corrected_path));
                        $addedAnyFile = true;
                    }

                    // ✅ Imágenes analizadas
                    if (in_array($type, ['analyzed', 'all']) && $img->processedImage->ai_response_json) {
                        $analyzedContent = $this->generateAnalyzedImageContent($img->processedImage);
                        if ($analyzedContent) {
                            // ✅ USAR NOMBRE ORIGINAL + _analyzed + EXTENSIÓN ORIGINAL
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

                // ✅ Actualizar progreso cada 25 imágenes
                if (($index + 1) % 25 === 0) {
                    $currentTotal = $totalProcessedSoFar + $processedInChunk;
                    $batch->update(['processed_images' => $currentTotal]);

                    Log::info("📊 Progreso chunk {$chunkNum}: {$processedInChunk}/{$images->count()} (Total: {$currentTotal}/{$batch->total_images})");
                }

            } catch (\Exception $e) {
                Log::warning("Error procesando imagen {$img->id} en chunk {$chunkNum}: " . $e->getMessage());
                continue;
            }
        }

        $zip->close();

        Log::info("✅ ZIP chunk {$chunkNum}/{$totalChunks} generado: {$zipName} ({$processedInChunk} imágenes válidas)");

        return $zipPath;
    }

    private function getOriginalImageName($image): string
    {
        // ✅ Usar original_path que es lo único disponible por ahora
        if ($image->original_path) {
            $originalFilename = basename($image->original_path);
            $baseName = pathinfo($originalFilename, PATHINFO_FILENAME);

            if ($baseName && $baseName !== 'image' && !empty($baseName)) {
                return $baseName;
            }
        }

        // ✅ Fallback: Intentar extraer desde corrected_path como último recurso
        if ($image->processedImage && $image->processedImage->corrected_path) {
            $processedFilename = basename($image->processedImage->corrected_path);

            // Intentar extraer nombre original de nombres como "yolo_processed_yolo_14047_1133364_68760f5a9c4c52.66069594.jpg"
            if (preg_match('/yolo_processed_yolo_\d+_\d+_([a-zA-Z0-9_\-\.]+)\./', $processedFilename, $matches)) {
                $extractedName = pathinfo($matches[1], PATHINFO_FILENAME);
                if (!empty($extractedName)) {
                    return $extractedName;
                }
            }

            // Intentar extraer desde nombres como "manual_6lAMNxF9.jpg"
            if (preg_match('/manual_([a-zA-Z0-9_\-]+)\./', $processedFilename, $matches)) {
                if (!empty($matches[1])) {
                    return $matches[1];
                }
            }
        }

        // ✅ Fallback final: Usar ID de imagen
        return "imagen_{$image->id}";
    }

    /**
     * ✅ NUEVO: Obtener extensión original de la imagen
     */
    private function getOriginalExtension($originalPath): string
    {
        if (!$originalPath) {
            return '.jpg'; // Extensión por defecto
        }

        $extension = '.' . strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));

        // ✅ Validar que sea una extensión de imagen válida
        $validExtensions = ['.jpg', '.jpeg', '.png', '.bmp', '.tiff', '.webp'];

        if (in_array($extension, $validExtensions)) {
            return $extension;
        }

        return '.jpg'; // Fallback
    }


    private function getFolderPathForZip($folder, $foldersById): string
    {
        $path = [];
        $current = $folder;
        $maxDepth = 10; // Prevenir bucles infinitos
        $depth = 0;

        while ($current && $depth < $maxDepth) {
            $name = str_replace(['/', '\\', '<', '>', ':', '"', '|', '?', '*'], '-', $current->name);
            $path[] = $name;
            $current = $foldersById[$current->parent_id] ?? null;
            $depth++;
        }

        return implode('/', array_reverse($path));
    }

    private function generateAnalyzedImageContent($processedImage): ?string
    {
        try {
            if (!$processedImage->ai_response_json) {
                return null;
            }

            $aiResponseJson = $processedImage->error_edits_json ?: $processedImage->ai_response_json;
            $correctedPath = $processedImage->corrected_path;
            $wasabi = Storage::disk('wasabi');

            if (!$wasabi->exists($correctedPath)) {
                Log::warning("Imagen procesada no encontrada en Wasabi: {$correctedPath}");
                return null;
            }

            $imageData = $wasabi->get($correctedPath);
            $manager = new ImageManager(new ImagickDriver());
            $image = $manager->read($imageData);

            // Interpretar JSON
            $parsed = json_decode($aiResponseJson, true);
            if (!$parsed) {
                Log::warning("No se pudo parsear AI response JSON");
                return null;
            }

            $predictions = $parsed['final'] ?? ($parsed['predictions'] ?? []);
            $minProbability = $parsed['minProbability'] ?? 0.5;

            // Colores por tipo
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

            // Guardar imagen temporal
            $tmpPath = storage_path('app/tmp/' . uniqid('analyzed_') . '.jpg');
            if (!is_dir(dirname($tmpPath))) {
                mkdir(dirname($tmpPath), 0755, true);
            }

            $image->toJpeg(90)->save($tmpPath);

            // Leer contenido y eliminar archivo temporal
            $content = file_get_contents($tmpPath);
            unlink($tmpPath);

            return $content;

        } catch (\Exception $e) {
            Log::warning("Error generando imagen analizada: " . $e->getMessage());
            return null;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ GenerateDownloadZipJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = DownloadBatch::find($this->batchId);
        if ($batch) {
            $batch->update([
                'status' => 'failed',
                'error' => $exception->getMessage()
            ]);
        }
    }
}
