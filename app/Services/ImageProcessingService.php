<?php
namespace App\Services;

use App\Models\Image;
use App\Models\ImageAnalysisResult;
use App\Models\ProcessedImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageProcessingService
{
    public function process(Image $image, $batchId = null): Image | null
    {
        if (!$image || !$image->original_path) {
            Log::error("❌ Imagen no encontrada para procesar (ID: {$this->imageId})");
            return null;
        }

        if ($batchId) {
            $batch = \App\Models\ImageBatch::find($batchId);
            if ($batch) {
                $batch->increment('processed');

                // Si ya está todo procesado, cerrar el batch
                if ($batch->processed >= $batch->total) {
                    $batch->update(['status' => 'completed']);
                }
            }
        }

        $wasabiDisk = Storage::disk('wasabi');
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');

        // Asegura que la carpeta tmp existe
        $tmpDir = storage_path('app/tmp');
        try {
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            if (!is_writable($tmpDir)) {
                throw new \Exception("El directorio no es escribible: $tmpDir");
            }
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo crear o acceder al directorio temporal", [
                'path' => $tmpDir,
                'error' => $e->getMessage(),
            ]);
            $image->update(['status' => 'error']);
            return $image;
        }

        // --- Paths temporales
        $filename = 'aligned_' . Str::random(8) . '.jpg';
        $originalTemp = $tmpDir . '/original_' . basename($image->original_path);
        $outputTemp = $tmpDir . '/' . $filename;
        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

        // --- Descargar original desde Wasabi
        try {
            $stream = $wasabiDisk->readStream($image->original_path);
            if (!$stream) {
                throw new \Exception('No se pudo abrir el stream desde Wasabi');
            }

            $local = fopen($originalTemp, 'w+b');
            if (!$local) {
                throw new \Exception("No se pudo crear el archivo local en: $originalTemp");
            }

            stream_copy_to_stream($stream, $local);
            fclose($stream);
            fclose($local);

        } catch (\Throwable $e) {
            Log::error("❌ No se pudo descargar la imagen original desde Wasabi", [
                'image_id' => $image->id,
                'path' => $image->original_path,
                'error' => $e->getMessage(),
            ]);
            $image->update(['status' => 'error']);
            return $image;
        }

        // --- Ejecutar script de recorte
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$originalTemp\" \"$outputTemp\"";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputTemp)) {
            Log::error("⚠️ Error procesando imagen ID {$image->id}", [
                'cmd' => $cmd,
                'returnCode' => $returnCode,
                'output' => $output,
            ]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            return $image;
        }

        try {
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo subir imagen recortada a Wasabi", [
                'path' => $wasabiProcessedPath,
                'error' => $e->getMessage(),
            ]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            return $image;
        }

        // --- Limpiar archivos temporales
        @unlink($originalTemp);
        @unlink($outputTemp);

        // --- Parsear salida del script
        $jsonData = json_decode(implode('', $output), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            Log::error("❌ Error parseando JSON en imagen ID {$image->id}", ['output' => $output]);
            $image->update(['status' => 'error']);
            return $image;
        }

        // --- Guardar imagen recortada
        $processed = $image->processedImage ?? new ProcessedImage();
        $processed->corrected_path = $wasabiProcessedPath;
        $image->processedImage()->save($processed);

        // --- Guardar resultados de IA
        $analysis = $image->analysisResult ?? new ImageAnalysisResult();
        $analysis->fill([
            'rows' => $jsonData['filas'] ?? null,
            'columns' => $jsonData['columnas'] ?? null,
            'integrity_score' => $jsonData['integridad'] ?? null,
            'luminosity_score' => $jsonData['luminosidad'] ?? null,
            'uniformity_score' => $jsonData['uniformidad'] ?? null,
        ]);
        $image->analysisResult()->save($analysis);

        $image->update(['status' => 'processed']);

        // ✅ Cargar relaciones y devolver la imagen ya lista
        $image->load(['processedImage', 'analysisResult']);

        Log::info("✅ Imagen ID {$image->id} procesada y almacenada en Wasabi correctamente");

        return $image;
    }
}
