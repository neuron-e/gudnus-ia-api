<?php

namespace App\Services;

use App\Models\Image;
use App\Models\ImageAnalysisResult;
use App\Models\ProcessedImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        $batch->touch();
    }

    public function process(Image $image, $batchId = null): Image | null
    {
        Log::info("🔧 INICIANDO ImageProcessingService YOLO para imagen {$image->id}");

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

        // ✅ CONFIGURACIÓN YOLO CORREGIDA
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_wrapped.py'); // ✅ SCRIPT YOLO CORRECTO
        $modelPath = storage_path('app/scripts/best.pt'); // ✅ MODELO YOLO

        // ✅ Usar configuración de .env si está disponible
        $modelPathEnv = env('YOLO_MODEL_PATH');
        if ($modelPathEnv && file_exists($modelPathEnv)) {
            $modelPath = $modelPathEnv;
        }

        // ✅ Obtener configuración del proyecto para filas/columnas
        $project = $image->project;
        $filas = $project?->cell_count ?? env('DEFAULT_PANEL_ROWS', 24);
        $columnas = $project?->column_count ?? env('DEFAULT_PANEL_COLUMNS', 6);
        $confidence = env('YOLO_DEFAULT_CONFIDENCE', 0.5);

        Log::debug("🤖 Configuración YOLO:", [
            'python_path' => $pythonPath,
            'script_path' => $scriptPath,
            'model_path' => $modelPath,
            'script_exists' => file_exists($scriptPath),
            'model_exists' => file_exists($modelPath),
            'filas' => $filas,
            'columnas' => $columnas,
            'confidence' => $confidence,
            'python_executable' => is_executable($pythonPath)
        ]);

        // Verificar archivos críticos
        if (!file_exists($scriptPath)) {
            $msg = "Script YOLO no encontrado: {$scriptPath}";
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        if (!file_exists($modelPath)) {
            $msg = "Modelo YOLO no encontrado: {$modelPath}";
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

        // ✅ PATHS TEMPORALES ÚNICOS (CORREGIDO)
        $uniqueId = uniqid('yolo_' . $image->id . '_' . getmypid() . '_', true);
        $filename = 'yolo_processed_' . $uniqueId . '.jpg';
        $originalTemp = $tmpDir . '/original_' . $uniqueId . '.jpg';
        $outputTemp = $tmpDir . '/' . $filename;
        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}"; // ✅ PATH CORREGIDO

        Log::debug("📁 Paths YOLO generados:", [
            'unique_id' => $uniqueId,
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

        // ✅ EJECUTAR SCRIPT YOLO CON TODOS LOS PARÁMETROS (CORREGIDO)
        $cmd = sprintf(
            '"%s" "%s" "%s" "%s" "%s" --filas %d --columnas %d --confidence %.2f',
            $pythonPath,
            $scriptPath,
            $originalTemp,
            $outputTemp,
            $modelPath,
            $filas,
            $columnas,
            $confidence
        );

        Log::debug("🤖 Ejecutando comando YOLO COMPLETO:", ['cmd' => $cmd]);

        // ✅ Ejecutar con timeout aumentado para YOLO
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        $timeoutSeconds = env('YOLO_TIMEOUT_SECONDS', 120); // ✅ Timeout para YOLO
        $start = time();

        while (is_resource($process)) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ((time() - $start) > $timeoutSeconds) {
                proc_terminate($process, 9); // SIGKILL
                $msg = "Timeout alcanzado al ejecutar YOLO (>$timeoutSeconds s)";
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

        Log::debug("🤖 Resultado comando YOLO:", [
            'return_code' => $returnCode,
            'stdout_length' => strlen($stdout),
            'stderr_length' => strlen($stderr),
            'output_file_exists' => file_exists($outputTemp),
            'output_file_size' => file_exists($outputTemp) ? filesize($outputTemp) : 0
        ]);

        if ($returnCode !== 0) {
            $msg = "Script YOLO falló (código: {$returnCode})";
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
            $msg = "Script YOLO no generó output válido";
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
            Log::debug("⬆️ Subiendo imagen YOLO procesada a Wasabi...");
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));

            if (!$wasabiDisk->exists($wasabiProcessedPath)) {
                throw new \Exception("El archivo no existe en Wasabi después de subirlo");
            }

            Log::debug("✅ Imagen YOLO subida a Wasabi correctamente");

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

        // ✅ PARSEAR JSON CON MÉTODO ROBUSTO (CORREGIDO)
        Log::debug("📊 Parseando output JSON del script YOLO...");
        $jsonData = $this->extractJsonFromOutput($stdout);
        if (!$jsonData) {
            $msg = "Error parseando JSON de YOLO: No se encontró JSON válido";
            Log::error("❌ " . $msg, [
                'stdout' => $stdout,
                'stdout_length' => strlen($stdout)
            ]);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        // ✅ Verificar que YOLO fue exitoso
        if (!($jsonData['success'] ?? false)) {
            $msg = "YOLO reportó fallo: " . ($jsonData['error'] ?? 'Error desconocido');
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }

        try {
            // ✅ Guardar datos en base de datos con métricas de YOLO
            Log::debug("💾 Guardando datos YOLO en base de datos...");

            $processed = $image->processedImage ?? new ProcessedImage();
            $processed->corrected_path = $wasabiProcessedPath;
            $image->processedImage()->save($processed);

            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill([
                'rows' => $jsonData['filas'] ?? $filas,
                'columns' => $jsonData['columnas'] ?? $columnas,
                'integrity_score' => $jsonData['integridad'] ?? null,
                'luminosity_score' => $jsonData['luminosidad'] ?? null,
                'uniformity_score' => $jsonData['uniformidad'] ?? null,
                // ✅ Métricas específicas de YOLO
                'detection_confidence' => $jsonData['confidence'] ?? null,
                'processing_method' => $jsonData['method'] ?? 'yolo_segmentation',
                'algorithm_version' => $jsonData['algorithm_version'] ?? 'yolo_v8_segmentation',
            ]);
            $image->analysisResult()->save($analysis);

            $image->update(['status' => 'processed']);
            $image->load(['processedImage', 'analysisResult']);

            Log::info("✅ Imagen {$image->id} procesada correctamente con YOLO", [
                'confidence' => $jsonData['confidence'] ?? 0,
                'method' => $jsonData['method'] ?? 'yolo_segmentation',
                'integridad' => $jsonData['integridad'] ?? 0,
                'unique_id' => $uniqueId
            ]);

            // ✅ Incrementar contador de batch procesado
/*            if ($batchId) {
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
            $msg = "Error guardando datos YOLO: " . $e->getMessage();
            Log::error("❌ " . $msg);
            $image->update(['status' => 'error']);
            $this->handleBatchError($batchId, $msg);
            return $image;
        }
    }

    /**
     * ✅ EXTRAE JSON VÁLIDO DEL OUTPUT - MÉTODO ROBUSTO
     */
    private function extractJsonFromOutput(string $output): ?array
    {
        Log::debug("🔍 Extrayendo JSON del output:", ['output_length' => strlen($output)]);

        // ✅ Método 1: Buscar último JSON válido línea por línea
        $lines = explode("\n", $output);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                $decoded = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    Log::debug("✅ JSON válido encontrado en línea", ['line_number' => $i + 1]);
                    return $decoded;
                }
            }
        }

        // ✅ Método 2: Buscar desde la última llave hasta el final válido
        $jsonStart = strrpos($output, '{');
        if ($jsonStart !== false) {
            $possibleJson = substr($output, $jsonStart);
            $decoded = json_decode($possibleJson, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug("✅ JSON válido encontrado desde última llave");
                return $decoded;
            }
        }

        // ✅ Método 3: Buscar patrón específico de nuestro JSON
        if (preg_match('/\{"success"\s*:\s*(true|false).*?\}(?=\s*$)/s', $output, $matches)) {
            $jsonCandidate = $matches[0];
            $decoded = json_decode($jsonCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug("✅ JSON válido encontrado con patrón success");
                return $decoded;
            }
        }

        // ✅ Método 4: Buscar cualquier JSON con structure conocida
        if (preg_match('/\{[^{}]*"method"\s*:\s*"[^"]*"[^{}]*\}/s', $output, $matches)) {
            $jsonCandidate = $matches[0];
            $decoded = json_decode($jsonCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug("✅ JSON válido encontrado con patrón method");
                return $decoded;
            }
        }

        // ✅ Método 5: Limpiar caracteres problemáticos y reintentar
        $cleanOutput = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $output); // Solo ASCII printable
        $cleanOutput = preg_replace('/\n+/', '\n', $cleanOutput); // Unificar saltos de línea

        if (preg_match('/\{.*"success".*\}/s', $cleanOutput, $matches)) {
            $jsonCandidate = $matches[0];
            $decoded = json_decode($jsonCandidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                Log::debug("✅ JSON válido encontrado después de limpieza");
                return $decoded;
            }
        }

        Log::error("❌ No se pudo extraer JSON válido del output", [
            'first_200_chars' => substr($output, 0, 200),
            'last_200_chars' => substr($output, -200),
            'total_length' => strlen($output),
            'contains_success' => str_contains($output, '"success"'),
            'contains_method' => str_contains($output, '"method"'),
            'json_attempts' => 5
        ]);

        return null;
    }
}
