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
use Intervention\Image\Geometry\Rectangle;
use Intervention\Image\Typography\Font;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hora máximo
    public $tries = 2;
    public $maxExceptions = 3;

    public function __construct(
        public int $projectId,
        public ?string $userEmail = null,
        public int $maxImagesPerPage = 50, // ✅ Limitar imágenes por página
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

            // ✅ 2. Obtener imágenes en lotes
            $allImages = $this->collectAllImages($project);
            $totalImages = $allImages->count();

            $reportGeneration->update(['total_images' => $totalImages]);

            if ($totalImages === 0) {
                throw new \Exception('No hay imágenes procesadas para generar el informe');
            }

            Log::info("📊 Total de imágenes a procesar: {$totalImages}");

            // ✅ 3. Dividir en chunks para PDFs grandes
            $shouldSplit = $totalImages > $this->maxImagesPerPage;

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

            // Mover a storage permanente
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

        try {
            foreach ($chunks as $index => $chunk) {
                Log::info("📄 Generando parte " . ($index + 1) . " de " . $chunks->count());

                // Pre-generar imágenes analizadas para este chunk
                $analyzedImages = $this->preGenerateAnalyzedImages($chunk, $tempDir, $reportGeneration);

                // Generar PDF parcial
                $partialPdfPath = $this->generatePDF(
                    $project,
                    $chunk,
                    $analyzedImages,
                    $tempDir,
                    $index + 1, // número de parte
                    $chunks->count() // total de partes
                );

                $pdfPaths[] = $partialPdfPath;

            }

            // ✅ Opcional: Combinar PDFs si es necesario
            if (count($pdfPaths) === 1) {
                $finalPath = $this->moveToFinalStorage($pdfPaths[0], $project);
            } else {
                $finalPath = $this->moveMultipleToFinalStorage($pdfPaths, $project);
            }

            $reportGeneration->update(['file_path' => $finalPath]);

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ Pre-generar imágenes analizadas en chunks para reducir memoria
     */
    private function preGenerateAnalyzedImages($images, $tempDir, $reportGeneration): array
    {
        if (!$this->includeAnalyzedImages) {
            return [];
        }

        $analyzedImages = [];
        $processed = 0;

        foreach ($images as $image) {
            if (!$image->processedImage) continue;

            try {
                $analyzedPath = $this->generateSingleAnalyzedImage($image->processedImage, $tempDir);
                if ($analyzedPath) {
                    $analyzedImages[$image->id] = $analyzedPath;
                }
            } catch (\Exception $e) {
                Log::warning("Error generando imagen analizada para imagen {$image->id}: " . $e->getLine());
                Log::warning("Error generando imagen analizada para imagen {$image->id}: " . $e->getFile());
                Log::warning("Error generando imagen analizada para imagen {$image->id}: " . $e->getMessage());

            }

            $processed++;
            // Update progress
            $reportGeneration->increment('processed_images');
            $reportGeneration->refresh(); // ✅ ahora refleja el valor actualizado

            // ✅ Liberar memoria cada 10 imágenes
            if ($processed % 10 === 0) {
                $this->freeMemory();
            }
        }

        return $analyzedImages;
    }

    /**
     * ✅ Generar una sola imagen analizada y guardarla en disco
     */
    private function generateSingleAnalyzedImage(ProcessedImage $processed, $tempDir): ?string
    {
        $wasabi = Storage::disk('wasabi');
        if (!$wasabi->exists($processed->corrected_path)) return null;

        // Usar archivo temporal en lugar de base64
        $tempImagePath = $tempDir . '/temp_' . uniqid() . '.jpg';
        file_put_contents($tempImagePath, $wasabi->get($processed->corrected_path));

        if (!file_exists($tempImagePath)) return null;

        try {
            $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Imagick\Driver());
            $image = $manager->read($tempImagePath);

            // Aplicar las anotaciones (mismo código que antes)
            $this->applyAnnotationsToImage($image, $processed);

            // Guardar imagen analizada
            $analyzedPath = $tempDir . '/analyzed_' . uniqid() . '.jpg';
            $image->toJpeg(85)->save($analyzedPath);

            return $analyzedPath;

        } finally {
            // Limpiar archivo temporal original
            @unlink($tempImagePath);
        }
    }

    /**
     * ✅ Aplicar anotaciones a la imagen (extraído del código original)
     */
    private function applyAnnotationsToImage($image, ProcessedImage $processed): void
    {
        $json = $processed->error_edits_json ?: $processed->ai_response_json;
        $prob = $processed->min_probability ?? 0.5;
        $response = json_decode($json, true);

        if (!isset($response['predictions'])) return;

        $filteredPredictions = array_filter($response['predictions'], function($prediction) use ($prob) {
            return !isset($prediction['probability']) || $prediction['probability'] >= $prob;
        });

        $errorColors = [
            'Intensidad' => '#FFA500',
            'Fingers' => '#00BFFF',
            'Black Edges' => '#FF0000',
            'Microgrietas' => '#8A2BE2',
            'Defectos' => '#32CD32',
            'Soldadura' => '#FF69B4',
            'Celdas dañadas' => '#FF0000',
        ];

        foreach ($filteredPredictions as $prediction) {
            $box = $prediction['boundingBox'];
            $left = (int) ($box['left'] * $image->width());
            $top = (int) ($box['top'] * $image->height());
            $width = (int) ($box['width'] * $image->width());
            $height = (int) ($box['height'] * $image->height());

            $tag = $prediction['tagName'] ?? '';
            $color = $errorColors[$tag] ?? '#FFFFFF';
            $label = sprintf('%s (%.1f%%)', $tag, ($prediction['probability'] ?? 0) * 100);

            // Rectángulo con grosor mejorado para PDF
            $rectangle = new Rectangle($width, $height);
            $rectangle->setBackgroundColor('rgba(0,0,0,0.1)'); // Fondo semi-transparente mejorado
            $rectangle->setBorder($color, 3); // Grosor aumentado para mejor visibilidad
            $image->drawRectangle($left, $top, $rectangle);

            // Texto con fondo para mejor legibilidad
            try {
                $font = new Font(resource_path('fonts/Inter_24pt-Regular.ttf'));
                $font->setColor('#FFFFFF');
                $font->setSize(20); // Tamaño aumentado

                // Agregar fondo semi-transparente al texto
                $textBg = new Rectangle(strlen($label) * 8, 20);
                $textBg->setBackgroundColor($color);
                $image->drawRectangle($left, max(0, $top - 25), $textBg);

                $image->text($label, $left + 2, max(10, $top - 8), $font);
            } catch (\Exception $e) {
                // Fallback si no encuentra la fuente
                $image->text($label, $left + 2, max(10, $top - 8), function($font) {
                    $font->color('#FFFFFF');
                    $font->size(14);
                });
            }
        }
    }

    /**
     * ✅ Generar el PDF usando rutas de archivos en lugar de base64
     */
    private function generatePDF($project, $images, $analyzedImages, $tempDir, $partNumber = null, $totalParts = null): string
    {
        $title = $partNumber ?
            "informe-electroluminiscencia-{$project->name}-parte-{$partNumber}-de-{$totalParts}" :
            "informe-electroluminiscencia-{$project->name}";

        $pdf = Pdf::loadView('pdf.project_report_optimized', [
            'project' => $project,
            'images' => $images,
            'analyzedImages' => $analyzedImages, // Rutas de archivos, no base64
            'partNumber' => $partNumber,
            'totalParts' => $totalParts,
        ]);

        $pdf->setPaper('a4', 'portrait')->setOptions([
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'Arial',
            'dpi' => 96,
            'isRemoteEnabled' => false, // ✅ Desactivar para mejor seguridad
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
    }

    private function moveToFinalStorage(string $pdfPath, $project): string
    {
        $fileName = basename($pdfPath);
        $finalPath = "reports/project_{$project->id}/{$fileName}";

        Storage::disk('local')->put($finalPath, file_get_contents($pdfPath));

        return $finalPath;
    }

    private function moveMultipleToFinalStorage(array $pdfPaths, $project): array
    {
        $finalPaths = [];

        foreach ($pdfPaths as $pdfPath) {
            $finalPaths[] = $this->moveToFinalStorage($pdfPath, $project);
        }

        return $finalPaths;
    }

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
        Log::error("❌ GenerateReportJob failed: " . $exception->getMessage());

        if ($reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first()) {
            $reportGeneration->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
