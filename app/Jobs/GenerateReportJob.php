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
use setasign\Fpdi\Fpdi;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // ✅ 2 horas para proyectos grandes
    public $tries = 2;
    public $maxExceptions = 3;

    public function __construct(
        public int $projectId,
        public ?string $userEmail = null,
        public int $maxImagesPerPage = 50, // ✅ REDUCIDO: Chunks muy pequeños
        public bool $includeAnalyzedImages = false, // ✅ Desactivado por defecto
        public bool $generateUnifiedPdf = true, // ✅ Merge activado por defecto
        public bool $compact = true // ✅ Merge activado por defecto
    ) {}

    /**
     * @throws \Throwable
     */
    public function handle(): void
    {
        $reportGeneration = ReportGeneration::where('project_id', $this->projectId)->latest()->first();

        try {
            Log::info("🚀 Iniciando generación de PDF para proyecto {$this->projectId}");

            $project = Project::with(['children'])->findOrFail($this->projectId);
            if ($this->compact) {
                $rows = [];

                // Si tu relación es diferente, ajusta 'processedImages'
                $project->processedImages()->orderBy('id')
                    ->chunk(500, function ($chunk) use (&$rows) {
                        foreach ($chunk as $pi) {
                            // Emitir token si no es válido
                            if (!$pi->isPublicTokenValid($pi->public_token)) {
                                $pi->issuePublicToken(now()->addMonths(6));
                            }

                            // 🔒 Normaliza posibles null/strings
                            $metrics    = $this->asArray($pi->metrics);
                            $errorsArr  = $this->asArray($pi->errors);

                            $thumb = $pi->thumb_url ?? ($pi->corrected_url ?? $pi->original_url);

                            $rows[] = [
                                'id'           => $pi->id,
                                'folder_path'  => $pi->folder_path,
                                'thumb'        => $thumb,
                                'integrity'    => $pi->image->analysisResult->integrity_score  ?? null,
                                'luminosity'   => $pi->image->analysisResult->luminosity_score ?? null,
                                'uniformity'   => $pi->image->analysisResult->uniformity_score ?? null,
                                'microcracks_count'   => $pi->image->analysisResult->microcracks_count ?? null,
                                'finger_interruptions_count'   => $pi->image->analysisResult->finger_interruptions_count ?? null,
                                'black_edges_count'   => $pi->image->analysisResult->black_edges_count ?? null,
                                'cells_with_different_intensity'   => $pi->image->analysisResult->cells_with_different_intensity ?? null,
                                'errors' => $this->acount($errorsArr), // ✅ nunca peta
                            'public_url'   => url("/report/processed-image/{$pi->id}?token={$pi->public_token}"),
                        ];
                    }
                });

                $totalImages = count($rows);

                // Render PDF ligero
                $pdf = app('dompdf.wrapper');
                $pdf->loadView('pdf.report_compact_table', [
                    'project'      => $project,
                    'rows'         => $rows,
                    'generated_at' => now(),
                ])->setPaper('a4', 'portrait');

                $tempDir = $this->createTempDirectory();
                $title = "informe-electroluminiscencia-completo-{$project->name}";
                $pdfPath = $tempDir . "/{$title}.pdf";
                $pdf->save($pdfPath);

                // ✅ Generar elementos estructurales (portada, índice, conclusiones)
                $structuralPdfs = $this->generateStructuralElements($project, $project->processedImages, $tempDir);

                // ✅ Combinar portada/índice + tabla compacta + conclusiones
                $unifiedPdfPath = $this->mergeAllPdfs($project, $structuralPdfs, [$pdfPath], $tempDir);

                // ✅ Mover a storage final
                $finalPath = $this->moveToFinalStorage($unifiedPdfPath, $project);
                $reportGeneration->update(['file_path' => $finalPath]);

                $sizeMB = round(filesize($unifiedPdfPath) / 1024 / 1024, 2);
                Log::info("✅ PDF compacto unificado generado: {$sizeMB}MB");

                $this->cleanupTempDirectory($tempDir);

            } else {
                $this->loadProjectStructure($project);

                $allImages = $this->collectAllImages($project);
                $totalImages = $allImages->count();

                if ($totalImages === 0) {
                    throw new \Exception('No hay imágenes procesadas para generar el informe');
                }

                $reportGeneration->update(['total_images' => $totalImages]);

                // ✅ ESTRATEGIA NUEVA: Siempre generar en partes + merge opcional
                if ($totalImages > 100 && $this->generateUnifiedPdf) {
                    // 🔄 FLUJO COMPLETO: Partes + Merge
                    $this->generatePartsAndMergeStrategy($project, $allImages, $reportGeneration);
                } else if ($totalImages > 100) {
                    // 📄 SOLO PARTES: Sin merge (para testing o preferencia usuario)
                    $this->generateMultiPartReport($project, $allImages, $reportGeneration);
                } else {
                    // 📋 PDF ÚNICO: Para proyectos pequeños
                    $this->generateSingleCompleteReport($project, $allImages, $reportGeneration);
                }

            }

            $reportGeneration->update([
                'status' => 'completed',
                'processed_images' => $totalImages,
                'completed_at' => now()
            ]);

            if ($this->userEmail) {
                Mail::to($this->userEmail)->send(new ReportGeneratedMail($reportGeneration));
            }

            Log::info("✅ PDF generado exitosamente para proyecto {$this->projectId}");

        } catch (\Throwable $e) {
            Log::error("❌ Error generando PDF: " . $e->getMessage());

            $reportGeneration->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /** Normaliza un valor a array. Acepta array, Collection o JSON string. */
    private function asArray(mixed $value): array
    {
        if ($value === null) return [];
        if (is_array($value)) return $value;
        if ($value instanceof \Illuminate\Support\Collection) return $value->toArray();
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /** Cuenta de forma segura (array|Countable) */
    private function acount(mixed $value): int
    {
        return is_countable($value) ? count($value) : 0;
    }

    private function generatePartsAndMergeStrategy($project, $allImages, $reportGeneration): void
    {
        $tempDir = $this->createTempDirectory();

        try {
            Log::info("🔄 Iniciando estrategia: Partes + Merge para {$allImages->count()} imágenes");

            // ✅ FASE 1: Generar todas las partes de contenido
            $contentPdfs = $this->generateContentParts($project, $allImages, $reportGeneration, $tempDir);

            // ✅ FASE 2: Generar elementos estructurales (portada, índice, conclusiones)
            $structuralPdfs = $this->generateStructuralElements($project, $allImages, $tempDir);

            // ✅ FASE 3: Combinar todo en PDF único
            $unifiedPdfPath = $this->mergeAllPdfs($project, $structuralPdfs, $contentPdfs, $tempDir);

            // ✅ FASE 4: Subir PDF final y limpiar partes
            $finalPath = $this->moveToFinalStorage($unifiedPdfPath, $project);
            $reportGeneration->update(['file_path' => $finalPath]);

            $sizeMB = round(filesize($unifiedPdfPath) / 1024 / 1024, 2);
            Log::info("✅ PDF unificado generado: {$sizeMB}MB");

        } finally {
            $this->cleanupTempDirectory($tempDir);
        }
    }

    /**
     * ✅ FASE 1: Generar todas las partes de contenido
     */
    private function generateContentParts($project, $allImages, $reportGeneration, $tempDir): array
    {
        $totalImages = $allImages->count();
        $maxImagesPerChunk = $this->getOptimalChunkSize($totalImages);
        $chunks = $allImages->chunk($maxImagesPerChunk);

        Log::info("📊 Generando {$chunks->count()} partes de contenido con ~{$maxImagesPerChunk} imágenes cada una");

        $contentPdfs = [];

        foreach ($chunks as $index => $chunk) {
            $partNum = $index + 1;
            Log::info("📄 Generando parte de contenido {$partNum} de {$chunks->count()} ({$chunk->count()} imágenes)");

            // ✅ Pre-generar imágenes analizadas para este chunk
            $analyzedImages = $this->preGenerateAnalyzedImages($chunk, $tempDir, $reportGeneration, "parte_{$partNum}");

            // ✅ Generar PDF de contenido (SIN portada ni índice)
            $contentPdf = $this->generateContentOnlyPdf($project, $chunk, $analyzedImages, $tempDir, $partNum, $chunks->count());
            $contentPdfs[] = $contentPdf;

            // ✅ Actualizar progreso
            $progressSoFar = $partNum * $maxImagesPerChunk;
            $reportGeneration->update(['processed_images' => min($progressSoFar, $totalImages)]);

            $this->freeMemoryAggressive();
        }

        return $contentPdfs;
    }

    /**
     * ✅ FASE 2: Generar elementos estructurales
     */
    private function generateStructuralElements($project, $allImages, $tempDir): array
    {
        Log::info("📋 Generando elementos estructurales del reporte");

        $structuralPdfs = [];

        // 1️⃣ PORTADA
        $coverPath = $this->generateCoverPage($project, $allImages, $tempDir);
        if ($coverPath) {
            $structuralPdfs['cover'] = $coverPath;
        }

        // 2️⃣ ÍNDICE/RESUMEN
        $indexPath = $this->generateIndexAndSummary($project, $allImages, $tempDir);
        if ($indexPath) {
            $structuralPdfs['index'] = $indexPath;
        }

        // 3️⃣ CONCLUSIONES
        $conclusionsPath = $this->generateConclusionsPage($project, $allImages, $tempDir);
        if ($conclusionsPath) {
            $structuralPdfs['conclusions'] = $conclusionsPath;
        }

        return $structuralPdfs;
    }

    /**
     * ✅ FASE 3: Combinar todos los PDFs en uno único
     */
    private function mergeAllPdfs($project, $structuralPdfs, $contentPdfs, $tempDir): string
    {
        Log::info("🔗 Combinando PDFs: " . (count($structuralPdfs) + count($contentPdfs)) . " archivos");

        $merger = new Fpdi();

        // ✅ 1. Agregar portada
        if (isset($structuralPdfs['cover'])) {
            $this->addPdfToMerger($merger, $structuralPdfs['cover']);
        }

        // ✅ 2. Agregar índice/resumen
        if (isset($structuralPdfs['index'])) {
            $this->addPdfToMerger($merger, $structuralPdfs['index']);
        }

        // ✅ 3. Agregar todas las partes de contenido en orden
        foreach ($contentPdfs as $contentPdf) {
            $this->addPdfToMerger($merger, $contentPdf);

            // Liberar memoria cada pocas partes
            if (count($contentPdfs) > 10) {
                $this->freeMemory();
            }
        }

        // ✅ 4. Agregar conclusiones
        if (isset($structuralPdfs['conclusions'])) {
            $this->addPdfToMerger($merger, $structuralPdfs['conclusions']);
        }

        // ✅ 5. Guardar PDF unificado
        $unifiedPath = $tempDir . "/informe-completo-{$project->name}-" . now()->format('Y-m-d') . ".pdf";
        $merger->Output($unifiedPath, 'F');

        Log::info("✅ PDFs combinados exitosamente");
        return $unifiedPath;
    }

    /**
     * ✅ HELPER: Agregar PDF al merger con manejo de errores
     */
    private function addPdfToMerger($merger, $pdfPath): void
    {
        if (!file_exists($pdfPath)) {
            Log::warning("⚠️ PDF no encontrado: {$pdfPath}");
            return;
        }

        try {
            $pageCount = $merger->setSourceFile($pdfPath);

            for ($pageNum = 1; $pageNum <= $pageCount; $pageNum++) {
                $merger->AddPage();
                $template = $merger->importPage($pageNum);
                $merger->useTemplate($template);
            }

            Log::debug("✅ Agregado: " . basename($pdfPath) . " ({$pageCount} páginas)");

        } catch (\Exception $e) {
            Log::error("❌ Error agregando PDF {$pdfPath}: " . $e->getMessage());
        }
    }

    /**
     * ✅ Generar portada del reporte
     */
    private function generateCoverPage($project, $allImages, $tempDir): string
    {
        Log::info("🎨 Generando portada del reporte");

        // ✅ Calcular estadísticas para la portada
        $stats = $this->calculateProjectStats($allImages);

        $pdf = Pdf::loadView('pdf.report_cover', [
            'project' => $project,
            'totalImages' => $allImages->count(),
            'stats' => $stats,
            'generatedAt' => now(),
        ]);

        $coverPath = $tempDir . "/00-portada.pdf";
        $pdf->save($coverPath);

        return $coverPath;
    }

    /**
     * ✅ Generar índice y resumen ejecutivo
     */
    private function generateIndexAndSummary($project, $allImages, $tempDir): string
    {
        Log::info("📋 Generando índice y resumen ejecutivo");

        $stats = $this->calculateProjectStats($allImages);
        $sections = $this->calculateSectionBreakdown($allImages);

        $pdf = Pdf::loadView('pdf.report_index', [
            'project' => $project,
            'stats' => $stats,
            'sections' => $sections,
            'totalImages' => $allImages->count(),
        ]);

        $indexPath = $tempDir . "/01-indice-resumen.pdf";
        $pdf->save($indexPath);

        return $indexPath;
    }

    /**
     * ✅ Generar página de conclusiones
     */
    private function generateConclusionsPage($project, $allImages, $tempDir): string
    {
        Log::info("📊 Generando página de conclusiones");

        $stats = $this->calculateProjectStats($allImages);
        $recommendations = $this->generateRecommendations($stats);

        $pdf = Pdf::loadView('pdf.report_conclusions', [
            'project' => $project,
            'stats' => $stats,
            'recommendations' => $recommendations,
            'totalImages' => $allImages->count(),
        ]);

        $conclusionsPath = $tempDir . "/99-conclusiones.pdf";
        $pdf->save($conclusionsPath);

        return $conclusionsPath;
    }

    /**
     * ✅ Generar PDF solo de contenido (sin elementos estructurales)
     */
    private function generateContentOnlyPdf($project, $images, $analyzedImages, $tempDir, $partNumber, $totalParts): string
    {
        $title = "contenido-parte-{$partNumber}-de-{$totalParts}";

        $pdf = Pdf::loadView('pdf.report_content_only', [
            'project' => $project,
            'images' => $images,
            'analyzedImages' => $analyzedImages,
            'partNumber' => $partNumber,
            'totalParts' => $totalParts,
            'showHeaders' => false, // ✅ Sin portadas ni headers principales
        ]);

        $pdf->setPaper('a4', 'portrait')->setOptions([
            'isHtml5ParserEnabled' => true,
            'isPhpEnabled' => false,
            'isRemoteEnabled' => false,
            'defaultFont' => 'Arial',
            'dpi' => 120,
            'debugPng' => false,
            'debugKeepTemp' => false,
            'debugCss' => false,
            'tempDir' => $tempDir,
        ]);

        $contentPath = $tempDir . "/{$title}.pdf";
        $pdf->save($contentPath);

        return $contentPath;
    }

    /**
     * ✅ Calcular estadísticas del proyecto para portada/conclusiones
     */
    private function calculateProjectStats($allImages): array
    {
        $totalImages = $allImages->count();
        $imagesWithErrors = 0;
        $errorsByType = [];
        $totalErrors = 0;

        foreach ($allImages as $image) {
            $processed = $image instanceof ProcessedImage ? $image : ($image->processedImage ?? null);
            if (!$processed || !$processed->ai_response_json) continue;

            $aiResponse = json_decode($processed->ai_response_json, true);
            if (!isset($aiResponse['predictions'])) continue;

            $imageHasErrors = false;
            foreach ($aiResponse['predictions'] as $prediction) {
                if (($prediction['probability'] ?? 0) >= 0.3) {
                    $errorType = $prediction['tagName'] ?? 'unknown';
                    $errorsByType[$errorType] = ($errorsByType[$errorType] ?? 0) + 1;
                    $totalErrors++;
                    $imageHasErrors = true;
                }
            }

            if ($imageHasErrors) {
                $imagesWithErrors++;
            }
        }

        return [
            'total_images' => $totalImages,
            'images_with_errors' => $imagesWithErrors,
            'images_clean' => $totalImages - $imagesWithErrors,
            'total_errors' => $totalErrors,
            'errors_by_type' => $errorsByType,
            'error_rate' => $totalImages > 0 ? round(($imagesWithErrors / $totalImages) * 100, 2) : 0,
        ];
    }

    /**
     * ✅ Calcular breakdown por secciones/carpetas
     */
    private function calculateSectionBreakdown($allImages): array
    {
        $sections = [];

        foreach ($allImages as $image) {
            $folderPath = $image->folder_path ?? 'Sin carpeta';

            if (!isset($sections[$folderPath])) {
                $sections[$folderPath] = [
                    'total_images' => 0,
                    'images_with_errors' => 0,
                    'errors' => []
                ];
            }

            $sections[$folderPath]['total_images']++;

            // Analizar errores si existen
            $processed = $image instanceof ProcessedImage ? $image : ($image->processedImage ?? null);
            if ($processed && $processed->ai_response_json) {
                $aiResponse = json_decode($processed->ai_response_json, true);
                if (isset($aiResponse['predictions'])) {
                    $hasErrors = false;
                    foreach ($aiResponse['predictions'] as $prediction) {
                        if (($prediction['probability'] ?? 0) >= 0.3) {
                            $errorType = $prediction['tagName'] ?? 'unknown';
                            $sections[$folderPath]['errors'][$errorType] = ($sections[$folderPath]['errors'][$errorType] ?? 0) + 1;
                            $hasErrors = true;
                        }
                    }
                    if ($hasErrors) {
                        $sections[$folderPath]['images_with_errors']++;
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * ✅ Generar recomendaciones basadas en estadísticas
     */
    private function generateRecommendations($stats): array
    {
        $recommendations = [];

        if ($stats['error_rate'] > 20) {
            $recommendations[] = "Se recomienda una inspección detallada ya que más del 20% de los módulos presentan defectos.";
        }

        if (isset($stats['errors_by_type']['cell_crack']) && $stats['errors_by_type']['cell_crack'] > 10) {
            $recommendations[] = "Alta incidencia de grietas en celdas. Revisar manipulación y transporte.";
        }

        if (isset($stats['errors_by_type']['soldering_issue']) && $stats['errors_by_type']['soldering_issue'] > 5) {
            $recommendations[] = "Problemas de soldadura detectados. Revisar proceso de manufactura.";
        }

        if ($stats['error_rate'] < 5) {
            $recommendations[] = "Excelente calidad general. Módulos dentro de parámetros aceptables.";
        }

        return $recommendations;
    }

    /**
     * ✅ Obtener tamaño óptimo de chunk para el servidor
     */
    private function getOptimalChunkSize($totalImages): int
    {
        return match(true) {
            $totalImages > 2000 => 100,  // Proyectos masivos
            $totalImages > 1000 => 150,  // Proyectos grandes
            $totalImages > 500 => 200,   // Proyectos medianos
            default => 300               // Proyectos pequeños
        };
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
