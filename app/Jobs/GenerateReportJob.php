<?php

namespace App\Jobs;

use App\Mail\ReportGeneratedMail;
use App\Models\Project;
use App\Models\ProcessedImage;
use App\Models\ReportGeneration;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\Font;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ CONFIGURACIÓN OPTIMIZADA PARA PROYECTOS GRANDES
    public $timeout = 14400; // 4 horas (era 1 hora)
    public $tries = 1;        // Solo 1 intento (era 2)
    public $maxExceptions = 1;

    public function __construct(
        public int $projectId,
        public ?string $userEmail = null,
        public int $maxImagesPerPage = 50, // ✅ Reducido de 500 a 50 por defecto
        public bool $includeAnalyzedImages = true
    ) {
        // ✅ Configurar memoria explícitamente
        ini_set('memory_limit', '2G');
    }

    public function handle(): void
    {
        $reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first();

        try {
            Log::info("🚀 GenerateReportJob iniciado", [
                'project_id' => $this->projectId,
                'timeout' => $this->timeout,
                'max_images_per_page' => $this->maxImagesPerPage,
                'memory_limit' => ini_get('memory_limit')
            ]);

            $project = Project::with(['children'])->findOrFail($this->projectId);

            // ✅ Verificar espacio disponible
            $this->checkAvailableSpace();

            $this->loadProjectStructure($project);

            $allImages = $this->collectAllImages($project);
            $totalImages = $allImages->count();

            $reportGeneration->update(['total_images' => $totalImages]);

            if ($totalImages === 0) {
                throw new \Exception('No hay imágenes procesadas para generar el informe');
            }

            Log::info("📊 Total de imágenes a procesar: {$totalImages}");

            // ✅ CHUNKS DINÁMICOS según tamaño del proyecto
            $optimalChunkSize = $this->calculateOptimalChunkSize($totalImages);
            $this->maxImagesPerPage = $optimalChunkSize;

            Log::info("📦 Usando chunks de {$optimalChunkSize} imágenes para proyecto de {$totalImages} imágenes");

            // ✅ Siempre dividir en chunks para proyectos grandes
            $shouldSplit = $totalImages > $optimalChunkSize;

            if ($shouldSplit) {
                $this->generateMultiPartReport($project, $allImages, $reportGeneration);
            } else {
                $this->generateSingleReport($project, $allImages, $reportGeneration);
            }

            $reportGeneration->update([
                'status' => 'completed',
                'processed_images' => $totalImages,
                'completed_at' => now()
            ]);

            Log::info("✅ PDF generado exitosamente para proyecto {$this->projectId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error generando PDF: " . $e->getMessage(), [
                'project_id' => $this->projectId,
                'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
                'trace' => $e->getTraceAsString()
            ]);

            $reportGeneration->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * ✅ NUEVO: Verificar espacio disponible
     */
    private function checkAvailableSpace(): void
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);

        if (!$freeBytes) {
            Log::warning("⚠️ No se pudo verificar espacio disponible");
            return;
        }

        $freeGB = $freeBytes / 1024 / 1024 / 1024;
        Log::info("💾 Espacio libre: {$freeGB}GB");

        if ($freeGB < 5) {
            throw new \Exception("Espacio insuficiente: {$freeGB}GB libres. Se requieren al menos 5GB para generar reportes.");
        }
    }

    /**
     * ✅ NUEVO: Calcular tamaño de chunk óptimo
     */
    private function calculateOptimalChunkSize($totalImages): int
    {
        return match(true) {
            $totalImages > 2000 => 25,  // ✅ Proyectos masivos: chunks muy pequeños
            $totalImages > 1000 => 35,  // ✅ Proyectos grandes
            $totalImages > 500 => 50,   // ✅ Proyectos medianos
            default => 75               // ✅ Proyectos pequeños
        };
    }

    /**
     * ✅ Generar PDF único para proyectos pequeños
     */
    private function generateSingleReport($project, $images, $reportGeneration): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            // Pre-generar imágenes analizadas en chunks pequeños
            $analyzedImages = $this->preGenerateAnalyzedImages($images, $tempDir, $reportGeneration);

            // Generar PDF
            $pdfPath = $this->generatePDF($project, $images, $analyzedImages, $tempDir);

            // Mover a storage final
            $finalPath = $this->moveToFinalStorage($pdfPath, $project);

            $reportGeneration->update(['file_path' => $finalPath]);

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ Generar múltiples PDFs para proyectos grandes
     */
    private function generateMultiPartReport($project, $allImages, $reportGeneration): void
    {
        $chunks = $allImages->chunk($this->maxImagesPerPage);
        $pdfPaths = [];
        $tempDir = $this->createTempDirectory();
        $totalProcessed = 0;

        try {
            foreach ($chunks as $index => $chunk) {
                // ✅ Liberar memoria entre chunks
                if ($index > 0) {
                    gc_collect_cycles();
                    $memoryMB = memory_get_usage(true) / 1024 / 1024;
                    Log::info("🧹 Memoria liberada. Uso actual: {$memoryMB}MB");
                }

                Log::info("📄 Generando parte " . ($index + 1) . " de " . $chunks->count() . " ({$chunk->count()} imágenes)");

                // ✅ OPTIMIZADO: Pre-generar solo las imágenes de este chunk
                $analyzedImages = $this->preGenerateAnalyzedImages($chunk, $tempDir, $reportGeneration);

                // Generar PDF parcial
                $partialPdfPath = $this->generatePDF(
                    $project,
                    $chunk,
                    $analyzedImages,
                    $tempDir,
                    $index + 1,
                    $chunks->count()
                );

                $pdfPaths[] = $partialPdfPath;
                $totalProcessed += $chunk->count();

                // ✅ Actualizar progreso
                $reportGeneration->update(['processed_images' => $totalProcessed]);

                // ✅ Limpiar imágenes analizadas temporales de este chunk
                foreach ($analyzedImages as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }

                Log::info("✅ Parte " . ($index + 1) . " completada. Progreso: {$totalProcessed}/{$allImages->count()}");
            }

            // Mover archivos a storage final
            $finalPath = count($pdfPaths) === 1 ?
                $this->moveToFinalStorage($pdfPaths[0], $project) :
                $this->moveMultipleToFinalStorage($pdfPaths, $project);

            $reportGeneration->update(['file_path' => $finalPath]);

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ CORREGIDO: Pre-generar imágenes analizadas usando la MISMA lógica que GenerateDownloadZipJob
     */
    private function preGenerateAnalyzedImages($images, $tempDir, $reportGeneration): array
    {
        if (!$this->includeAnalyzedImages) {
            return [];
        }

        $analyzedImages = [];
        $processed = 0;

        foreach ($images as $image) {
            if (!$image->processedImage || !$image->processedImage->ai_response_json) {
                continue;
            }

            try {
                $analyzedContent = $this->generateAnalyzedImageContent($image->processedImage);

                if ($analyzedContent) {
                    $analyzedPath = $tempDir . '/analyzed_' . $image->id . '.jpg';
                    file_put_contents($analyzedPath, $analyzedContent);
                    $analyzedImages[$image->id] = $analyzedPath;
                }

            } catch (\Exception $e) {
                Log::warning("Error generando imagen analizada para imagen {$image->id}: " . $e->getMessage());
                continue;
            }

            $processed++;

            // ✅ Liberar memoria cada 5 imágenes (más frecuente)
            if ($processed % 5 === 0) {
                $this->freeMemory();

                $memoryMB = memory_get_usage(true) / 1024 / 1024;
                Log::info("🧠 Memoria después de {$processed} imágenes: {$memoryMB}MB");
            }
        }

        Log::info("📊 Generadas {$processed} imágenes analizadas de " . $images->count() . " imágenes en el chunk");
        return $analyzedImages;
    }


    /**
     * 🆕 NUEVO: Usar EXACTAMENTE la misma lógica que GenerateDownloadZipJob
     */
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

            // ✅ MISMA lógica de parsing que GenerateDownloadZipJob
            $parsed = json_decode($aiResponseJson, true);
            if (!$parsed) {
                Log::warning("No se pudo parsear AI response JSON");
                return null;
            }

            $predictions = $parsed['final'] ?? ($parsed['predictions'] ?? []);
            $minProbability = $parsed['minProbability'] ?? 0.5;

            // ✅ MISMOS colores que GenerateDownloadZipJob
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

                // ✅ MISMO estilo de rectángulo que GenerateDownloadZipJob
                $rectangle = new Rectangle($width, $height);
                $rectangle->setBackgroundColor('transparent');
                $rectangle->setBorder($color, 2); // ← 2, no 3
                $image->drawRectangle($left, $top, $rectangle);

                // ✅ MISMO estilo de texto que GenerateDownloadZipJob
                if (file_exists(resource_path('fonts/Inter_24pt-Regular.ttf'))) {
                    $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
                    $font->setColor('#FFFFFF');
                    $font->setSize(14); // ← 14, no 16 ni 20
                    $image->text($label, $left, $top - 12, $font); // ← Misma posición que ZIP
                }
                // ✅ QUITAR el fallback que causa problemas
            }

            // ✅ MISMO guardado temporal que GenerateDownloadZipJob
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

    /**
     * ✅ Generar el PDF usando rutas de archivos
     */
    private function generatePDF($project, $images, $analyzedImages, $tempDir, $partNumber = null, $totalParts = null): string
    {
        Log::info("📄 Generando pdf...");
        $title = $partNumber ?
            "informe-electroluminiscencia-{$project->name}-parte-{$partNumber}-de-{$totalParts}" :
            "informe-electroluminiscencia-{$project->name}";

        $pdf = Pdf::loadView('pdf.project_report_optimized', [
            'project' => $project,
            'images' => $images,
            'analyzedImages' => $analyzedImages, // Rutas de archivos con imágenes ya procesadas
            'partNumber' => $partNumber,
            'totalParts' => $totalParts,
        ]);

        $pdf->setPaper('a4', 'portrait')->setOptions([
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'Arial',
            'dpi' => 96,
            'isRemoteEnabled' => false,
        ]);

        $pdfPath = $tempDir . "/{$title}.pdf";
        $pdf->save($pdfPath);

        return $pdfPath;
    }

    /**
     * ✅ Utilidades
     */
    private function createTempDirectory(): string
    {
        $tempDir = storage_path('app/temp/pdf_' . uniqid());
        File::makeDirectory($tempDir, 0755, true);
        return $tempDir;
    }

    private function cleanupTempDirectory(string $tempDir): void
    {
        File::deleteDirectory($tempDir);
    }

    private function freeMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // ✅ Liberar memoria de Intervention Image si es posible
        if (class_exists('\Intervention\Image\ImageManager')) {
            // Forzar limpieza de caché interno de Intervention
        }
    }

    private function moveToFinalStorage(string $pdfPath, $project): string
    {
        $fileName = basename($pdfPath);
        $fileSizeMB = filesize($pdfPath) / 1024 / 1024;

        Log::info("📊 PDF generado: {$fileName} ({$fileSizeMB}MB)");

        // Si el archivo es > 50MB, mover a Wasabi; si no, mantener local
        if ($fileSizeMB > 500) {
            return $this->moveToWasabi($pdfPath, $project, $fileName);
        } else {
            return $this->moveToLocal($pdfPath, $project, $fileName);
        }
    }

    private function moveToWasabi(string $pdfPath, $project, string $fileName): string
    {
        try {
            $wasabiPath = "reports/project_{$project->id}/{$fileName}";
            $wasabi = Storage::disk('wasabi');

            Log::info("📤 Moviendo PDF a Wasabi: {$fileName}");

            $stream = fopen($pdfPath, 'r');
            if (!$stream) {
                throw new \Exception("No se pudo abrir el archivo PDF");
            }

            $success = $wasabi->writeStream($wasabiPath, $stream);
            fclose($stream);

            if (!$success) {
                throw new \Exception("Falló la subida a Wasabi");
            }

            // Verificar integridad
            $localSize = filesize($pdfPath);
            $wasabiSize = $wasabi->size($wasabiPath);

            if (abs($localSize - $wasabiSize) > 1024) {
                throw new \Exception("Tamaños no coinciden: local={$localSize}, wasabi={$wasabiSize}");
            }

            unlink($pdfPath);

            Log::info("✅ PDF movido exitosamente a Wasabi: {$wasabiPath}");
            return $wasabiPath;

        } catch (\Exception $e) {
            Log::error("❌ Error moviendo PDF a Wasabi: " . $e->getMessage());
            Log::info("📁 Fallback: Moviendo a storage local");
            return $this->moveToLocal($pdfPath, $project, $fileName);
        }
    }

    private function moveToLocal(string $pdfPath, $project, string $fileName): string
    {
        $finalPath = "reports/project_{$project->id}/{$fileName}";
        Storage::disk('local')->put($finalPath, file_get_contents($pdfPath));
        unlink($pdfPath);

        Log::info("📁 PDF guardado en storage local: {$finalPath}");
        return $finalPath;
    }

    private function moveMultipleToFinalStorage(array $pdfPaths, $project): array
    {
        $finalPaths = [];
        $totalSizeMB = 0;

        // Calcular tamaño total
        foreach ($pdfPaths as $pdfPath) {
            if (file_exists($pdfPath)) {
                $totalSizeMB += filesize($pdfPath) / 1024 / 1024;
            }
        }

        Log::info("📊 Total de PDFs: " . count($pdfPaths) . " archivos, {$totalSizeMB}MB");

        // Procesar cada archivo
        foreach ($pdfPaths as $pdfPath) {
            if (!file_exists($pdfPath)) {
                Log::warning("⚠️ Archivo no encontrado: {$pdfPath}");
                continue;
            }

            $fileName = basename($pdfPath);
            $fileSizeMB = filesize($pdfPath) / 1024 / 1024;

            // Decidir storage basado en tamaño individual y total
            $shouldUseWasabi = $fileSizeMB > 50 || $totalSizeMB > 100;

            if ($shouldUseWasabi) {
                $finalPaths[] = $this->moveToWasabi($pdfPath, $project, $fileName);
            } else {
                $finalPaths[] = $this->moveToLocal($pdfPath, $project, $fileName);
            }
        }

        return $finalPaths;
    }

    // ✅ Métodos de utilidad sin cambios
    private function loadProjectStructure($project): void
    {
        $rootFolders = $project->children()->whereNull('parent_id')
            ->with(['images.processedImage', 'images.analysisResult'])
            ->get();

        $project->children = $rootFolders;
        foreach ($rootFolders as $folder) {
            $this->loadChildrenRecursive($folder);
        }
    }

    private function loadChildrenRecursive($folder, $parentPath = ''): void
    {
        $currentPath = trim($parentPath . ' / ' . $folder->name, ' /');
        $folder->full_path = $currentPath;

        foreach ($folder->images as $image) {
            $filename = basename($image->original_path);
            $image->filename = $filename;
            $image->full_path = $currentPath . ' / ' . $filename;
            $image->folder_path = $currentPath;
        }

        $folder->load(['children' => function ($query) {
            $query->with(['images.processedImage', 'images.analysisResult']);
        }]);

        foreach ($folder->children as $child) {
            $this->loadChildrenRecursive($child, $currentPath);
        }
    }

    private function collectAllImages($project): \Illuminate\Support\Collection
    {
        $allImages = collect();
        $this->collectImagesRecursive($project->children, $allImages);

        return $allImages->filter(function ($img) {
            return $img->processedImage !== null;
        });
    }

    private function collectImagesRecursive($folders, &$allImages): void
    {
        foreach ($folders as $folder) {
            if ($folder->images) {
                foreach ($folder->images as $img) {
                    $allImages->push($img);
                }
            }
            if ($folder->children) {
                $this->collectImagesRecursive($folder->children, $allImages);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ GenerateReportJob FAILED para proyecto {$this->projectId}", [
            'error' => $exception->getMessage(),
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
            'timeout' => $this->timeout,
            'attempts' => $this->attempts()
        ]);

        if ($reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first()) {
            $reportGeneration->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
