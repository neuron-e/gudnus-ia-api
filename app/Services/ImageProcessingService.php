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
    private function handleBatchError(?int $batchId, string $msg): void
    {
        if (!$batchId) return;

        $batch = \App\Models\ImageBatch::find($batchId);
        if (!$batch) return;

        $batch->increment('errors');
        $batch->update([
            'error_messages' => array_merge($batch->error_messages ?? [], [$msg]),
        ]);
    }

    public function process(Image $image, $batchId = null): Image | null
    {
        if (!$image || !$image->original_path) {
            Log::error("❌ Imagen no encontrada para procesar (ID: {$image?->id})");
            $this->handleBatchError($batchId, "Imagen no encontrada para procesar (ID: {$image?->id})");
            return null;
        }

        $wasabiDisk = Storage::disk('wasabi');
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');
        $tmpDir = storage_path('app/tmp');

        try {
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            if (!is_writable($tmpDir)) {
                throw new \Exception("El directorio no es escribible: $tmpDir");
            }
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo crear o acceder al directorio temporal", ['path' => $tmpDir, 'error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "Error accediendo a tmp: " . $e->getMessage());
            return $image;
        }

        // Paths temporales
        $filename = 'aligned_' . Str::random(8) . '.jpg';
        $originalTemp = $tmpDir . '/original_' . basename($image->original_path);
        $outputTemp = $tmpDir . '/' . $filename;
        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

        // Descargar imagen desde Wasabi
        try {
            $stream = $wasabiDisk->readStream($image->original_path);
            if (!$stream) throw new \Exception('No se pudo abrir el stream desde Wasabi');

            $local = fopen($originalTemp, 'w+b');
            if (!$local) throw new \Exception("No se pudo crear archivo local: $originalTemp");

            stream_copy_to_stream($stream, $local);
            fclose($stream);
            fclose($local);
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo descargar imagen original", ['image_id' => $image->id, 'error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "Descarga fallida para imagen ID {$image->id}");
            return $image;
        }

        // Ejecutar script Python
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$originalTemp\" \"$outputTemp\"";
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputTemp)) {
            Log::error("⚠️ Error procesando imagen ID {$image->id}", ['cmd' => $cmd, 'output' => $output]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            $this->handleBatchError($batchId, "Script falló para imagen ID {$image->id}");
            return $image;
        }

        try {
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo subir imagen procesada", ['path' => $wasabiProcessedPath, 'error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, "Upload Wasabi falló en imagen ID {$image->id}");
            return $image;
        }

        @unlink($originalTemp);
        @unlink($outputTemp);

        // Parsear JSON
        $jsonData = json_decode(implode('', $output), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            Log::error("❌ Error parseando JSON en imagen ID {$image->id}", ['output' => $output]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "JSON inválido para imagen ID {$image->id}");
            return $image;
        }

        // Guardar datos
        $processed = $image->processedImage ?? new ProcessedImage();
        $processed->corrected_path = $wasabiProcessedPath;
        $image->processedImage()->save($processed);

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
        $image->load(['processedImage', 'analysisResult']);

        Log::info("✅ Imagen ID {$image->id} procesada y almacenada correctamente");

        // Incrementar batch antes de nada
        if ($batchId) {
            $batch = \App\Models\ImageBatch::find($batchId);
            if ($batch) {
                Log::info("🟢 Incrementado processed: {$batch->processed}");
                $batch->increment('processed');
                $batch->refresh();
                Log::info("🟢 Refrescado processed: {$batch->processed}");

                if (($batch->processed + $batch->errors) >= $batch->total) {
                    $batch->update(['status' => 'completed']);
                }
            }
        }

        return $image;
    }

}
