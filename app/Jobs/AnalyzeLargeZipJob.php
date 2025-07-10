<?php

namespace App\Jobs;

use App\Models\ZipAnalysis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeLargeZipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hora
    public $tries = 2;

    public function __construct(public string $analysisId) {}

    public function handle()
    {
        $analysis = ZipAnalysis::find($this->analysisId);
        if (!$analysis) {
            Log::error("❌ Análisis {$this->analysisId} no encontrado");
            return;
        }

        try {
            Log::info("🔍 Iniciando análisis de ZIP", ['analysis_id' => $this->analysisId]);

            // ✅ Actualizar estado
            $analysis->update(['status' => 'processing', 'progress' => 10]);

            // ✅ Verificar que el ZIP existe
            $zipPath = storage_path("app/{$analysis->file_path}");
            if (!file_exists($zipPath)) {
                throw new \Exception("Archivo ZIP no encontrado: {$zipPath}");
            }

            // ✅ Crear directorio de extracción
            $extractPath = $analysis->getExtractedPath();
            if (!file_exists($extractPath)) {
                mkdir($extractPath, 0755, true);
            }

            $analysis->update(['progress' => 30]);

            // ✅ Extraer ZIP
            $zip = new \ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \Exception('No se pudo abrir el archivo ZIP');
            }

            if (!$zip->extractTo($extractPath)) {
                $zip->close();
                throw new \Exception('No se pudo extraer el archivo ZIP');
            }

            $zip->close();
            $analysis->update(['progress' => 60]);

            // ✅ Analizar imágenes
            $imageData = $this->analyzeImages($extractPath);

            $analysis->update([
                'status' => 'completed',
                'progress' => 100,
                'total_files' => $imageData['total_files'],
                'valid_images' => $imageData['valid_images'], // ✅ Usar nombre correcto
                'images_data' => $imageData['images'] // ✅ Usar nombre correcto
            ]);

            Log::info("✅ Análisis completado", [
                'analysis_id' => $this->analysisId,
                'valid_images' => $imageData['valid_images']
            ]);

        } catch (\Exception $e) {
            Log::error("❌ Error en análisis {$this->analysisId}: " . $e->getMessage());

            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }

    private function analyzeImages(string $extractPath): array
    {
        $images = [];
        $totalFiles = 0;
        $validImages = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $totalFiles++;

                $filename = $file->getFilename();
                $extension = strtolower($file->getExtension());

                // ✅ Filtros de archivos válidos
                if (in_array($extension, ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp']) &&
                    !str_starts_with($filename, '.') &&
                    !str_contains(strtolower($file->getPath()), '__macosx') &&
                    $filename !== '.ds_store') {

                    $relativePath = str_replace($extractPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $relativePath = str_replace('\\', '/', $relativePath); // Normalizar separadores

                    $images[] = [
                        'name' => $filename,
                        'path' => $relativePath,
                        'size' => $file->getSize()
                    ];

                    $validImages++;
                }
            }
        }

        return [
            'total_files' => $totalFiles,
            'valid_images' => $validImages,
            'images' => $images
        ];
    }

    public function failed(\Exception $exception): void
    {
        Log::error("❌ AnalyzeLargeZipJob FAILED para análisis {$this->analysisId}: " . $exception->getMessage());

        $analysis = ZipAnalysis::find($this->analysisId);
        if ($analysis) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
