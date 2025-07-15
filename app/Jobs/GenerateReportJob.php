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

    public $timeout = 7200; // ✅ 2 horas para proyectos grandes
    public $tries = 2;
    public $maxExceptions = 3;

    public function __construct(
        public int $projectId,
        public ?string $userEmail = null,
        public int $maxImagesPerPage = 5000, // ✅ CAMBIO CLAVE: Límite muy alto por defecto
        public bool $includeAnalyzedImages = true
    ) {}

    public function handle(): void
    {
        $reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first();

        try {
            Log::info("🚀 Iniciando generación de PDF para proyecto {$this->projectId}");

            $project = Project::with(['children'])->findOrFail($this->projectId);

            // ✅ 1. Preparar datos básicos del proyecto
            $this->loadProjectStructure($project);

            // ✅ 2. Obtener TODAS las imágenes
            $allImages = $this->collectAllImages($project);
            $totalImages = $allImages->count();

            $reportGeneration->update(['total_images' => $totalImages]);

            if ($totalImages === 0) {
                throw new \Exception('No hay imágenes procesadas para generar el informe');
            }

            Log::info("📊 Total de imágenes a procesar: {$totalImages}");

            // ✅ 3. NUEVA LÓGICA: Solo fragmentar si es REALMENTE necesario
            $memoryLimitMB = $this->getAvailableMemoryMB();
            $estimatedMemoryNeededMB = $totalImages * 2; // ~2MB por imagen estimado

            Log::info("🧠 Memoria disponible: {$memoryLimitMB}MB, estimada necesaria: {$estimatedMemoryNeededMB}MB");

            if ($estimatedMemoryNeededMB > ($memoryLimitMB * 0.8)) {
                // Solo si realmente no hay memoria suficiente
                Log::info("⚠️ Memoria insuficiente, generando en partes optimizadas");
                $this->generateOptimizedMultiPartReport($project, $allImages, $reportGeneration);
            } else {
                // ✅ CASO NORMAL: PDF único completo
                Log::info("✅ Generando PDF único completo");
                $this->generateSingleCompleteReport($project, $allImages, $reportGeneration);
            }

            $reportGeneration->update([
                'status' => 'completed',
                'processed_images' => $totalImages,
                'completed_at' => now()
            ]);

            // ✅ 4. Enviar notificación por email
            if ($this->userEmail) {
                Mail::to($this->userEmail)->send(new ReportGeneratedMail($reportGeneration));
            }

            Log::info("✅ PDF generado exitosamente para proyecto {$this->projectId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error generando PDF: " . $e->getMessage(), [
                'project_id' => $this->projectId,
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
     * ✅ MÉTODO PRINCIPAL: Generar PDF único completo
     */
    private function generateSingleCompleteReport($project, $allImages, $reportGeneration): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            Log::info("📄 Generando PDF único con {$allImages->count()} imágenes");

            // ✅ Pre-generar todas las imágenes analizadas
            $analyzedImages = $this->preGenerateAnalyzedImages($allImages, $tempDir, $reportGeneration);

            // ✅ Generar el PDF completo de una vez
            $pdfPath = $this->generateCompletePDF($project, $allImages, $analyzedImages, $tempDir);

            // ✅ Mover a storage final
            $finalPath = $this->moveToFinalStorage($pdfPath, $project);

            $reportGeneration->update(['file_path' => $finalPath]);

            $sizeMB = round(filesize($pdfPath) / 1024 / 1024, 2);
            Log::info("✅ PDF único generado: {$sizeMB}MB");

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ SOLO USAR SI ES NECESARIO: Múltiples partes optimizadas (máximo 5 partes)
     */
    private function generateOptimizedMultiPartReport($project, $allImages, $reportGeneration): void
    {
        $totalImages = $allImages->count();

        // ✅ Máximo 5 partes, mínimo 500 imágenes por parte
        $maxParts = 5;
        $minImagesPerPart = 500;
        $optimalImagesPerPart = max($minImagesPerPart, ceil($totalImages / $maxParts));

        $chunks = $allImages->chunk($optimalImagesPerPart);
        $actualParts = $chunks->count();

        Log::info("📊 Generando {$actualParts} partes optimizadas con ~{$optimalImagesPerPart} imágenes cada una");

        $tempDir = $this->createTempDirectory();
        $pdfPaths = [];

        try {
            foreach ($chunks as $index => $chunk) {
                Log::info("📄 Generando parte " . ($index + 1) . " de {$actualParts} ({$chunk->count()} imágenes)");

                // Pre-generar imágenes analizadas para este chunk
                $analyzedImages = $this->preGenerateAnalyzedImages($chunk, $tempDir, $reportGeneration);

                // Generar PDF parcial
                $partialPdfPath = $this->generateCompletePDF(
                    $project,
                    $chunk,
                    $analyzedImages,
                    $tempDir,
                    $index + 1,
                    $actualParts
                );

                $pdfPaths[] = $partialPdfPath;

                // ✅ Liberar memoria después de cada chunk
                $this->freeMemory();
            }

            // ✅ Mover archivos a storage final
            $finalPaths = $this->moveMultipleToFinalStorage($pdfPaths, $project);
            $reportGeneration->update(['file_path' => $finalPaths]);

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ MÉTODO UNIFICADO: Generar PDF completo (único o parte)
     */
    private function generateCompletePDF($project, $images, $analyzedImages, $tempDir, $partNumber = null, $totalParts = null): string
    {
        $title = $partNumber
            ? "informe-electroluminiscencia-{$project->name}-parte-{$partNumber}-de-{$totalParts}"
            : "informe-electroluminiscencia-completo-{$project->name}";

        Log::info("📄 Generando PDF: {$title}");

        // ✅ Configuración optimizada para PDFs grandes
        $pdf = Pdf::loadView('pdf.project_report_optimized', [
            'project' => $project,
            'images' => $images,
            'analyzedImages' => $analyzedImages,
            'partNumber' => $partNumber,
            'totalParts' => $totalParts,
        ]);

        $pdf->setPaper('a4', 'portrait')->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'Arial',
            'dpi' => 150, // ✅ Mayor calidad
            'debugPng' => false,
            'debugKeepTemp' => false,
            'debugCss' => false,
            'logOutputFile' => storage_path('logs/dompdf.log'),
            'tempDir' => $tempDir,
            'chroot' => [storage_path(), public_path()], // ✅ Restricciones de seguridad
        ]);

        $pdfPath = $tempDir . "/{$title}.pdf";
        $pdf->save($pdfPath);

        $sizeMB = round(filesize($pdfPath) / 1024 / 1024, 2);
        Log::info("✅ PDF generado: {$sizeMB}MB - {$pdfPath}");

        return $pdfPath;
    }

    /**
     * ✅ Pre-generar imágenes analizadas con procesamiento en lotes
     */
    private function preGenerateAnalyzedImages($images, $tempDir, $reportGeneration): array
    {
        if (!$this->includeAnalyzedImages) {
            return [];
        }

        $analyzedImages = [];
        $processed = 0;
        $batchSize = 20; // Procesar de 20 en 20

        Log::info("🔄 Pre-generando {$images->count()} imágenes analizadas...");

        foreach ($images->chunk($batchSize) as $batch) {
            foreach ($batch as $image) {
                if (!$image->processedImage) continue;

                try {
                    $analyzedContent = $this->generateAnalyzedImageContent($image->processedImage);

                    if ($analyzedContent) {
                        $analyzedPath = $tempDir . '/analyzed_' . $image->id . '.jpg';
                        file_put_contents($analyzedPath, $analyzedContent);
                        $analyzedImages[$image->id] = $analyzedPath;
                    }

                } catch (\Exception $e) {
                    Log::warning("Error generando imagen analizada para imagen {$image->id}: " . $e->getMessage());
                }

                $processed++;
                $reportGeneration->increment('processed_images');
            }

            // ✅ Liberar memoria después de cada lote
            $this->freeMemory();

            if ($processed % 100 === 0) {
                Log::info("🔄 Procesadas {$processed}/{$images->count()} imágenes analizadas");
            }
        }

        Log::info("✅ {$processed} imágenes analizadas pre-generadas");
        return $analyzedImages;
    }

    /**
     * ✅ Generar contenido de imagen analizada (igual que GenerateDownloadZipJob)
     */
    private function generateAnalyzedImageContent($processedImage): ?string
    {
        try {
            if (!$processedImage->ai_response_json) {
                return null;
            }

            $wasabi = Storage::disk('wasabi');
            if (!$wasabi->exists($processedImage->corrected_path)) {
                return null;
            }

            // ✅ Descargar imagen original
            $imageContent = $wasabi->get($processedImage->corrected_path);

            // ✅ Procesar con Intervention Image
            $manager = new ImageManager(new ImagickDriver());
            $image = $manager->read($imageContent);

            // ✅ Aplicar análisis visual
            $this->applyAnalysisToImage($image, $processedImage);

            // ✅ Convertir a JPEG y retornar contenido
            return $image->toJpeg(90)->toString();

        } catch (\Exception $e) {
            Log::warning("Error generando contenido de imagen analizada: " . $e->getMessage());
            return null;
        }
    }

    /**
     * ✅ Aplicar visualizaciones de análisis a la imagen
     */
    private function applyAnalysisToImage($image, ProcessedImage $processedImage): void
    {
        $aiResponseJson = $processedImage->error_edits_json
            ? json_decode($processedImage->error_edits_json, true)
            : json_decode($processedImage->ai_response_json, true);

        if (!isset($aiResponseJson['predictions'])) return;

        $errorColors = [
            'cell_crack' => '#FF0000',
            'cell_cracking' => '#FF4500',
            'cell_burning' => '#8B0000',
            'corrosion' => '#FFA500',
            'bad_soldering' => '#FFFF00',
            'soldering_issue' => '#FFFF00',
            'soldering_failure' => '#FFFF00',
            'diode_failure' => '#800080',
            'diode_issue' => '#800080',
            'inactive_cell' => '#0000FF',
            'short_circuit' => '#FF1493',
            'pid' => '#32CD32',
            'potential_induced_degradation' => '#32CD32',
            'glass_breakage' => '#00FFFF',
            'broken_glass' => '#00FFFF'
        ];

        foreach ($aiResponseJson['predictions'] as $prediction) {
            if (($prediction['probability'] ?? 0) < 0.3) continue;

            $boundingBox = $prediction['boundingBox'] ?? null;
            if (!$boundingBox) continue;

            $left = intval($boundingBox['left'] * $image->width());
            $top = intval($boundingBox['top'] * $image->height());
            $width = intval($boundingBox['width'] * $image->width());
            $height = intval($boundingBox['height'] * $image->height());

            $tag = $prediction['tagName'] ?? '';
            $color = $errorColors[$tag] ?? '#FFFFFF';
            $label = sprintf('%s (%.1f%%)', $tag, ($prediction['probability'] ?? 0) * 100);

            // ✅ Dibujar rectángulo
            $rectangle = new Rectangle($width, $height);
            $rectangle->setBorder($color, 3);
            $rectangle->setBackgroundColor('rgba(0,0,0,0.1)');
            $image->drawRectangle($left, $top, $rectangle);

            // ✅ Dibujar etiqueta
            try {
                $fontPath = resource_path('fonts/Inter_24pt-Regular.ttf');
                if (file_exists($fontPath)) {
                    $font = new Font($fontPath);
                    $font->setColor('#FFFFFF');
                    $font->setSize(16);
                } else {
                    $font = null;
                }

                // Fondo para el texto
                $textBg = new Rectangle(strlen($label) * 8, 20);
                $textBg->setBackgroundColor($color);
                $image->drawRectangle($left, max(0, $top - 25), $textBg);

                // Texto
                if ($font) {
                    $image->text($label, $left + 2, max(10, $top - 8), $font);
                } else {
                    $image->text($label, $left + 2, max(10, $top - 8), function($font) {
                        $font->color('#FFFFFF');
                        $font->size(14);
                    });
                }
            } catch (\Exception $e) {
                Log::debug("Error aplicando fuente: " . $e->getMessage());
            }
        }
    }

    /**
     * ✅ HELPER: Obtener memoria disponible
     */
    private function getAvailableMemoryMB(): int
    {
        $memoryLimit = ini_get('memory_limit');

        if ($memoryLimit === '-1') {
            return 2048; // Sin límite, asumir 2GB disponibles
        }

        $unit = strtoupper(substr($memoryLimit, -1));
        $value = intval($memoryLimit);

        return match($unit) {
            'G' => $value * 1024,
            'M' => $value,
            'K' => intval($value / 1024),
            default => intval($value / 1024 / 1024)
        };
    }

    /**
     * ✅ HELPER: Liberar memoria
     */
    private function freeMemory(): void
    {
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        $memoryMB = round(memory_get_usage(true) / 1024 / 1024, 2);
        $peakMB = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

        if ($memoryMB > 800) { // Log si supera 800MB
            Log::info("🧠 Memoria alta: {$memoryMB}MB actual, {$peakMB}MB pico");
        }
    }

    /**
     * ✅ Mover archivo único a storage final
     */
    private function moveToFinalStorage(string $localPath, $project): string
    {
        $wasabi = Storage::disk('wasabi');
        $timestamp = now()->format('Y-m-d_H-i-s');

        $filename = "informe-completo-{$project->name}-{$timestamp}.pdf";
        $filename = $this->sanitizeFilename($filename);
        $wasabiPath = "reports/project_{$project->id}/{$filename}";

        $content = file_get_contents($localPath);
        $wasabi->put($wasabiPath, $content);

        $sizeMB = round(strlen($content) / 1024 / 1024, 2);
        Log::info("📤 Reporte completo subido: {$sizeMB}MB -> {$wasabiPath}");

        return $wasabiPath;
    }

    /**
     * ✅ Mover múltiples archivos (solo si es necesario)
     */
    private function moveMultipleToFinalStorage(array $pdfPaths, $project): array
    {
        $wasabi = Storage::disk('wasabi');
        $finalPaths = [];
        $timestamp = now()->format('Y-m-d_H-i-s');

        foreach ($pdfPaths as $index => $localPath) {
            $partNum = str_pad($index + 1, 2, '0', STR_PAD_LEFT);
            $totalParts = str_pad(count($pdfPaths), 2, '0', STR_PAD_LEFT);

            $filename = "informe-{$project->name}-parte-{$partNum}-de-{$totalParts}-{$timestamp}.pdf";
            $filename = $this->sanitizeFilename($filename);
            $wasabiPath = "reports/project_{$project->id}/{$filename}";

            $content = file_get_contents($localPath);
            $wasabi->put($wasabiPath, $content);
            $finalPaths[] = $wasabiPath;

            $sizeMB = round(strlen($content) / 1024 / 1024, 2);
            Log::info("📤 Parte {$partNum} subida: {$sizeMB}MB");
        }

        return $finalPaths;
    }

    // ✅ Resto de métodos auxiliares (sanitizeFilename, createTempDirectory, etc.)
    private function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '-', $filename);
        return preg_replace('/-+/', '-', trim($filename, '-'));
    }

    private function createTempDirectory(): string
    {
        $tempDir = storage_path('app/temp/pdf_' . uniqid());
        if (!File::exists($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }
        return $tempDir;
    }

    private function cleanupTempDirectory(string $tempDir): void
    {
        if (File::exists($tempDir)) {
            File::deleteDirectory($tempDir);
        }
    }

    /**
     * ✅ Cargar estructura del proyecto con jerarquía de carpetas
     */
    private function loadProjectStructure($project): void
    {
        // Cargar solo las carpetas raíz (parent_id = null) con relaciones necesarias
        $rootFolders = \App\Models\Folder::where('project_id', $project->id)
            ->whereNull('parent_id')
            ->with(['images.processedImage', 'images.analysisResult'])
            ->get();

        if ($rootFolders->count() > 0) {
            $project->children = $rootFolders;

            // Cargar hijos recursivamente para cada carpeta raíz
            foreach ($rootFolders as $folder) {
                $this->loadChildrenRecursive($folder);
            }
        } else {
            $project->children = collect([]);
        }
    }

    /**
     * ✅ Cargar recursivamente los hijos de una carpeta
     */
    private function loadChildrenRecursive($folder, $parentPath = ''): void
    {
        // Construir el path actual para esta carpeta
        $currentPath = trim($parentPath . ' / ' . $folder->name, ' /');
        $folder->full_path = $currentPath;

        // Asignar el path a las imágenes de esta carpeta
        foreach ($folder->images as $image) {
            $filename = basename($image->original_path);
            $image->filename = $filename;
            $image->full_path = $currentPath . ' / ' . $filename;
            $image->folder_path = $currentPath;
        }

        // Cargar hijos y relaciones
        $folder->load(['children' => function ($query) {
            $query->with(['images.processedImage', 'images.analysisResult']);
        }]);

        // Aplicar recursivamente a cada hijo
        foreach ($folder->children as $child) {
            $this->loadChildrenRecursive($child, $currentPath);
        }
    }

    /**
     * ✅ MÉTODO PRINCIPAL: Recopilar todas las imágenes del proyecto
     */
    private function collectAllImages($project): \Illuminate\Support\Collection
    {
        $allImages = collect();
        $this->collectImagesRecursive($project->children, $allImages);

        // ✅ Filtrar solo imágenes que tienen processedImage
        return $allImages->filter(function ($img) {
            return $img->processedImage !== null;
        });
    }

    /**
     * ✅ HELPER: Recopilar imágenes recursivamente de todas las carpetas
     */
    private function collectImagesRecursive($folders, &$allImages): void
    {
        foreach ($folders as $folder) {
            // Agregar imágenes de la carpeta actual
            if ($folder->images) {
                foreach ($folder->images as $img) {
                    $allImages->push($img);
                }
            }

            // Procesar carpetas hijas recursivamente
            if ($folder->children) {
                $this->collectImagesRecursive($folder->children, $allImages);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ GenerateReportJob failed: " . $exception->getMessage(), [
            'project_id' => $this->projectId,
            'trace' => $exception->getTraceAsString()
        ]);

        // Actualizar el estado del reporte a fallido
        if ($reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first()) {
            $reportGeneration->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
