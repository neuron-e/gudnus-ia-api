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

    public $timeout = 3600;
    public $tries = 2;

    public function __construct(public string $analysisId) {}

    public function handle()
    {
        $analysis = ZipAnalysis::findOrFail($this->analysisId);

        try {
            // ✅ FIX: Asegurar que los campos sean strings
            $filename = is_array($analysis->original_filename)
                ? json_encode($analysis->original_filename)
                : (string)$analysis->original_filename;

            Log::info("🔍 Iniciando análisis de ZIP grande: {$filename}");

            $analysis->update(['status' => 'processing', 'progress' => 5]);

            $zipPath = Storage::disk('local')->path($analysis->file_path);

            if (!file_exists($zipPath)) {
                throw new \Exception("Archivo ZIP no encontrado: {$zipPath}");
            }

            // ✅ Usar PHP ZipArchive en lugar de comando unzip (compatible Windows)
            $result = $this->analyzeZipWithPHP($zipPath, $analysis);

            if (!is_array($result) || !isset($result['valid_images']) || !isset($result['all_files'])) {
                throw new \Exception("Resultado de análisis inválido");
            }

            $validImages = $result['valid_images'];
            $allFiles = $result['all_files'];

            if (!is_array($validImages)) $validImages = [];
            if (!is_array($allFiles)) $allFiles = [];

            $analysis->update([
                'status' => 'completed',
                'progress' => 100,
                'total_files' => count($allFiles),
                'valid_images' => count($validImages),
                'images_data' => json_encode($validImages, JSON_UNESCAPED_SLASHES),
            ]);

            Log::info("✅ Análisis completado: " . count($validImages) . " imágenes válidas encontradas");

        } catch (\Throwable $e) {
            Log::error("❌ Error analizando ZIP: " . $e->getMessage());
            Log::error("❌ Stack trace: " . $e->getTraceAsString());

            $analysis->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }

    /**
     * ✅ NUEVO: Análisis usando PHP ZipArchive (compatible Windows/Linux)
     */
    private function analyzeZipWithPHP(string $zipPath, ZipAnalysis $analysis): array
    {
        Log::info("🔍 Analizando ZIP con PHP ZipArchive: {$zipPath}");

        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== TRUE) {
            throw new \Exception("No se pudo abrir el ZIP. Código de error: {$result}");
        }

        try {
            $allFiles = [];
            $validImages = [];
            $totalEntries = $zip->numFiles;

            Log::info("📊 Procesando {$totalEntries} entradas del ZIP");

            for ($i = 0; $i < $totalEntries; $i++) {
                $entry = $zip->statIndex($i);

                if ($entry === false) {
                    Log::warning("⚠️ No se pudo leer entrada {$i}");
                    continue;
                }

                $fileName = $entry['name'];
                $fileSize = $entry['size'];

                // Saltar directorios
                if (substr($fileName, -1) === '/') continue;

                $allFiles[] = [
                    'path' => $fileName,
                    'size' => $fileSize
                ];

                // ✅ Verificar si es imagen válida
                if ($this->isValidImageFile($fileName, $fileSize)) {
                    $validImages[] = [
                        'path' => $fileName,
                        'name' => basename($fileName),
                        'size' => $fileSize,
                        'folder' => dirname($fileName) !== '.' ? dirname($fileName) : ''
                    ];
                }

                // ✅ Actualizar progreso cada 1000 archivos
                if ($i % 1000 === 0) {
                    $progress = 20 + (($i / $totalEntries) * 60); // 20% - 80%
                    $analysis->update(['progress' => (int)$progress]);
                    Log::info("📈 Progreso análisis: {$progress}% ({$i}/{$totalEntries})");
                }
            }

            $analysis->update(['progress' => 80]);

            // ✅ Ordenar imágenes por nombre numérico
            usort($validImages, function($a, $b) {
                $aNum = $this->extractNumber($a['name']);
                $bNum = $this->extractNumber($b['name']);
                return $aNum <=> $bNum;
            });

            Log::info("📊 Análisis completado: " . count($allFiles) . " archivos, " . count($validImages) . " imágenes válidas");

            return [
                'all_files' => $allFiles,
                'valid_images' => $validImages
            ];

        } finally {
            $zip->close();
        }
    }

    /**
     * ✅ Verificar si es archivo de imagen válido
     */
    private function isValidImageFile(string $filePath, int $size): bool
    {
        // Verificar extensión
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'])) {
            return false;
        }

        // Verificar tamaño mínimo (evitar thumbnails)
        if ($size < 10000) { // 10KB mínimo
            return false;
        }

        // Filtrar archivos del sistema
        $fileName = strtolower(basename($filePath));
        $excludePatterns = [
            '__macosx', '.ds_store', 'thumbs.db', '.tmp', '.temp'
        ];

        foreach ($excludePatterns as $pattern) {
            if (strpos($fileName, $pattern) !== false || strpos($filePath, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * ✅ Extraer número de un nombre de archivo para ordenamiento
     */
    private function extractNumber(string $filename): int
    {
        if (preg_match('/(\d+)/', $filename, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ AnalyzeLargeZipJob failed: " . $exception->getMessage());

        $analysis = ZipAnalysis::find($this->analysisId);
        if ($analysis) {
            $analysis->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage()
            ]);
        }
    }
}
