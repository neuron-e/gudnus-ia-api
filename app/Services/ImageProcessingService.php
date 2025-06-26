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
        $batch->touch(); // ✅ Actualizar timestamp cuando hay errores
    }

    public function process(Image $image, $batchId = null): Image | null
    {
        if (!$image || !$image->original_path) {
            Log::error("❌ Imagen no encontrada para procesar (ID: {$image?->id})");
            $this->handleBatchError($batchId, "Imagen no encontrada para procesar (ID: {$image?->id})");
            return null;
        }

        // ✅ Actualizar timestamp del batch al inicio
        if ($batchId) {
            $batch = \App\Models\ImageBatch::find($batchId);
            if ($batch) {
                $batch->touch();
            }
        }

        $wasabiDisk = Storage::disk('wasabi');
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');
        $tmpDir = storage_path('app/tmp');

        // ✅ Paths temporales con ID único para evitar colisiones
        $uniqueId = uniqid('proc_' . $image->id . '_', true);
        $filename = 'aligned_' . $uniqueId . '.jpg';
        $originalTemp = $tmpDir . '/original_' . $uniqueId . '.jpg';
        $outputTemp = $tmpDir . '/' . $filename;
        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

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

        // Descargar imagen desde Wasabi
        try {
            $stream = $wasabiDisk->readStream($image->original_path);
            if (!$stream) throw new \Exception('No se pudo abrir el stream desde Wasabi');

            $local = fopen($originalTemp, 'w+b');
            if (!$local) throw new \Exception("No se pudo crear archivo local: $originalTemp");

            stream_copy_to_stream($stream, $local);
            fclose($stream);
            fclose($local);

            // ✅ Verificar que el archivo se descargó correctamente
            if (!file_exists($originalTemp) || filesize($originalTemp) === 0) {
                throw new \Exception("Archivo descargado está vacío o no existe");
            }

        } catch (\Throwable $e) {
            Log::error("❌ No se pudo descargar imagen original", ['image_id' => $image->id, 'error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "Descarga fallida para imagen ID {$image->id}: " . $e->getMessage());
            @unlink($originalTemp); // ✅ Cleanup
            return $image;
        }

        // Ejecutar script Python
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$originalTemp\" \"$outputTemp\"";
        Log::debug("Ejecutando comando Python", ['cmd' => $cmd, 'image_id' => $image->id]);

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputTemp)) {
            Log::error("⚠️ Error procesando imagen ID {$image->id}", [
                'cmd' => $cmd,
                'output' => $output,
                'return_code' => $returnCode,
                'output_exists' => file_exists($outputTemp)
            ]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, "Script falló para imagen ID {$image->id} (código: $returnCode)");
            return $image;
        }

        // ✅ Verificar que el archivo de salida tiene contenido
        if (filesize($outputTemp) === 0) {
            Log::error("❌ Archivo de salida está vacío para imagen ID {$image->id}");
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, "Archivo procesado vacío para imagen ID {$image->id}");
            return $image;
        }

        try {
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));

            // ✅ Verificar que se subió correctamente
            if (!$wasabiDisk->exists($wasabiProcessedPath)) {
                throw new \Exception("El archivo no existe en Wasabi después de subirlo");
            }

        } catch (\Throwable $e) {
            Log::error("❌ No se pudo subir imagen procesada", ['path' => $wasabiProcessedPath, 'error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, "Upload Wasabi falló en imagen ID {$image->id}: " . $e->getMessage());
            return $image;
        }

        // ✅ Cleanup de archivos temporales
        @unlink($originalTemp);
        @unlink($outputTemp);

        // Parsear JSON
        $jsonData = json_decode(implode('', $output), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            Log::error("❌ Error parseando JSON en imagen ID {$image->id}", [
                'output' => $output,
                'json_error' => json_last_error_msg()
            ]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "JSON inválido para imagen ID {$image->id}: " . json_last_error_msg());
            return $image;
        }

        try {
            // Guardar datos
            $processed = $image->processedImage ?? new ProcessedImage();
            $processed->corrected_path = $wasabiProcessedPath;
            $processed->status = 'completed'; // ✅ Marcar como completado
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

            // ✅ Incrementar batch con mejor logging y verificación de finalización
            if ($batchId) {
                $batch = \App\Models\ImageBatch::find($batchId);
                if ($batch) {
                    $oldProcessed = $batch->processed;
                    $batch->increment('processed');
                    $batch->touch(); // Actualizar timestamp
                    $batch->refresh();

                    Log::info("🟢 Batch {$batch->id}: processed {$oldProcessed} → {$batch->processed}");

                    // Verificar si el batch está completo
                    $totalProcessed = $batch->processed + ($batch->errors ?? 0);
                    if ($totalProcessed >= $batch->total) {
                        $finalStatus = ($batch->errors ?? 0) > 0 ? 'completed_with_errors' : 'completed';
                        $batch->update(['status' => $finalStatus]);
                        Log::info("🎉 Batch {$batch->id} completado con status: {$finalStatus} ({$batch->processed} exitosas, {$batch->errors} errores)");
                    }
                }
            }

            return $image;

        } catch (\Throwable $e) {
            Log::error("❌ Error guardando datos para imagen ID {$image->id}", ['error' => $e->getMessage()]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, "Error guardando datos para imagen ID {$image->id}: " . $e->getMessage());
            return $image;
        }
    }
}
