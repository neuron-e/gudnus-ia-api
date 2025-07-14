<?php
// Versión CORREGIDA del ImageProcessingService con logging detallado

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

        // ✅ CORREGIDO: Descomentar incremento de errores
        $batch->increment('errors');
        $batch->update([
            'error_messages' => array_merge($batch->error_messages ?? [], [$msg]),
        ]);
        $batch->touch();
    }

    public function process(Image $image, $batchId = null): Image | null
    {
        Log::info("🔧 INICIANDO ImageProcessingService para imagen {$image->id}");

        if (!$image || !$image->original_path) {
            $msg = "Imagen no encontrada para procesar (ID: {$image?->id})";
            Log::error("❌ " . $msg);
            $this->handleBatchError($batchId, $msg);
            return null;
        }

        // ✅ Verificaciones iniciales
        $wasabiDisk = Storage::disk('wasabi');
        if (!$wasabiDisk->exists($image->original_path)) {
            $msg = "Imagen no existe en Wasabi: {$image->original_path}";
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        // ✅ Verificar configuración de Python
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_wrapped.py');
        $modelPath = storage_path('app/scripts/best.pt'); // ✅ Modelo YOLO

        Log::debug("🐍 Configuración Python:", [
            'python_path' => $pythonPath,
            'script_path' => $scriptPath,
            'script_exists' => file_exists($scriptPath),
            'python_executable' => is_executable($pythonPath)
        ]);

        if (!file_exists($scriptPath)) {
            $msg = "Script de Python no encontrado: {$scriptPath}";
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        // ✅ Verificar directorio temporal
        $tmpDir = storage_path('app/tmp');
        if (!file_exists($tmpDir)) {
            try {
                mkdir($tmpDir, 0755, true);
                Log::info("📁 Directorio temporal creado: {$tmpDir}");
            } catch (\Exception $e) {
                $msg = "No se pudo crear directorio temporal: " . $e->getMessage();
                Log::error("❌ " . $msg);
                $image->update(['status' => 'error']);
                $this->handleBatchError($batchId, $msg);
                return $image;
            }
        }

        if (!is_writable($tmpDir)) {
            $msg = "Directorio temporal no es escribible: {$tmpDir}";
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        // ✅ Paths temporales con ID único
        $nameWithoutExt = pathinfo($image->filename ?? 'panel', PATHINFO_FILENAME);
        $filename = 'processed_' . $nameWithoutExt . '.jpg';
        $originalTemp = $tmpDir . '/original_' . $filename;
        $outputTemp = $tmpDir . '/' . $filename;
        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

        Log::debug("📁 Paths generados:", [
            'original_temp' => $originalTemp,
            'output_temp' => $outputTemp,
            'wasabi_processed' => $wasabiProcessedPath
        ]);

        try {
            // ✅ Descargar imagen desde Wasabi
            Log::debug("⬇️ Descargando imagen desde Wasabi...");

            $stream = $wasabiDisk->readStream($image->original_path);
            if (!$stream) {
                throw new \Exception('No se pudo abrir el stream desde Wasabi');
            }

            $local = fopen($originalTemp, 'w+b');
            if (!$local) {
                throw new \Exception("No se pudo crear archivo local: $originalTemp");
            }

            stream_copy_to_stream($stream, $local);
            fclose($stream);
            fclose($local);

            // ✅ Verificar descarga
            if (!file_exists($originalTemp) || filesize($originalTemp) === 0) {
                throw new \Exception("Archivo descargado está vacío o no existe");
            }

            $fileSize = filesize($originalTemp);
            Log::debug("✅ Imagen descargada correctamente", [
                'file_size' => $fileSize,
                'file_exists' => file_exists($originalTemp)
            ]);

        } catch (\Throwable $e) {
            $msg = "Error descargando imagen: " . $e->getMessage();
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            @unlink($originalTemp);
            return $image;
        }

        // ✅ Ejecutar script Python con mejor logging
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$originalTemp\" \"$outputTemp\" \"$modelPath\"";
        Log::debug("🐍 Ejecutando comando Python:", ['cmd' => $cmd]);

        // ✅ Ejecutar con timeout y captura de errores
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        $timeoutSeconds = 60;
        $start = time();

        while (is_resource($process)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > $timeoutSeconds) {
                proc_terminate($process, 9); // SIGKILL
                $msg = "Timeout alcanzado al ejecutar el script (>$timeoutSeconds s)";
                Log::error("❌ " . $msg);
                $image->update(['status' => 'error']);
                $this->handleBatchError($batchId, $msg);
                @unlink($originalTemp);
                @unlink($outputTemp);
                return $image;
            }
            usleep(200000); // 200ms
        }

        fclose($pipes[0]); // Cerrar stdin

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        Log::debug("🐍 Resultado comando Python:", [
            'return_code' => $returnCode,
            'stdout_length' => strlen($stdout),
            'stderr_length' => strlen($stderr),
            'output_file_exists' => file_exists($outputTemp),
            'output_file_size' => file_exists($outputTemp) ? filesize($outputTemp) : 0
        ]);

        if ($returnCode !== 0) {
            $msg = "Script Python falló (código: {$returnCode})";
            Log::error("❌ " . $msg, [
                'stdout' => $stdout,
                'stderr' => $stderr
            ]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, $msg . " - STDERR: " . $stderr);
            return $image;
        }

        if (!file_exists($outputTemp) || filesize($outputTemp) === 0) {
            $msg = "Script Python no generó output válido";
            Log::error("❌ " . $msg, [
                'output_exists' => file_exists($outputTemp),
                'output_size' => file_exists($outputTemp) ? filesize($outputTemp) : 0,
                'stdout' => $stdout
            ]);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        try {
            // ✅ Subir imagen procesada
            Log::debug("⬆️ Subiendo imagen procesada a Wasabi...");
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));

            if (!$wasabiDisk->exists($wasabiProcessedPath)) {
                throw new \Exception("El archivo no existe en Wasabi después de subirlo");
            }

            Log::debug("✅ Imagen subida a Wasabi correctamente");

        } catch (\Throwable $e) {
            $msg = "Error subiendo imagen procesada: " . $e->getMessage();
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            @unlink($originalTemp);
            @unlink($outputTemp);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        // ✅ Cleanup de archivos temporales
        @unlink($originalTemp);
        @unlink($outputTemp);

        // ✅ Parsear JSON output
        Log::debug("📊 Parseando output JSON del script...");
        $jsonData = json_decode($stdout, true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            $msg = "Error parseando JSON: " . json_last_error_msg();
            Log::error("❌ " . $msg, [
                'stdout' => $stdout,
                'json_error' => json_last_error_msg()
            ]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        try {
            // ✅ Guardar datos en base de datos
            Log::debug("💾 Guardando datos en base de datos...");

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

            Log::info("✅ Imagen {$image->id} procesada correctamente");

/*            // ✅ CORREGIDO: Descomentar incremento de batch procesado
            if ($batchId) {
                $batch = \App\Models\ImageBatch::find($batchId);
                if ($batch) {
                    $oldProcessed = $batch->processed;
                    $batch->increment('processed');
                    $batch->touch();
                    Log::debug("📊 Batch {$batch->id}: processed {$oldProcessed} → {$batch->processed}");
                }
            }*/

            return $image;

        } catch (\Throwable $e) {
            $msg = "Error guardando datos: " . $e->getMessage();
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }
    }
}
