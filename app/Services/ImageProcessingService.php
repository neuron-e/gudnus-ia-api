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

        // ✅ ESTRATEGIA 1: Intentar YOLO primero
        Log::info("🤖 Intentando procesamiento con YOLO...");
        $result = $this->processWithYolo($image, $batchId);

        if ($result && $result->status === 'processed') {
            Log::info("✅ YOLO exitoso para imagen {$image->id}");
            return $result;
        }

        // ✅ ESTRATEGIA 2: Fallback al método mejorado
        Log::warning("⚠️ YOLO falló, usando fallback mejorado para imagen {$image->id}");
        return $this->processWithImprovedFallback($image, $batchId);
    }

    /**
     * ✅ ESTRATEGIA YOLO (método actual)
     */
    private function processWithYolo(Image $image, $batchId = null): Image | null
    {
        try {
            // ✅ CONFIGURACIÓN YOLO
            $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
            $scriptPath = storage_path('app/scripts/process_image_wrapped.py');
            $modelPath = storage_path('app/scripts/best.pt');

            // ✅ Usar configuración de .env si está disponible
            $modelPathEnv = env('YOLO_MODEL_PATH');
            if ($modelPathEnv && file_exists($modelPathEnv)) {
                $modelPath = $modelPathEnv;
            }

            // ✅ Obtener configuración del proyecto
            $project = $image->project;
            $filas = $project?->cell_count ?? env('DEFAULT_PANEL_ROWS', 24);
            $columnas = $project?->column_count ?? env('DEFAULT_PANEL_COLUMNS', 6);
            $confidence = env('YOLO_DEFAULT_CONFIDENCE', 0.5);

            // Verificar archivos críticos
            if (!file_exists($scriptPath)) {
                throw new \Exception("Script YOLO no encontrado: {$scriptPath}");
            }

            if (!file_exists($modelPath)) {
                throw new \Exception("Modelo YOLO no encontrado: {$modelPath}");
            }

            // ✅ PATHS TEMPORALES ÚNICOS
            $tmpDir = storage_path('app/tmp');
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            $uniqueId = uniqid('yolo_' . $image->id . '_' . getmypid() . '_', true);
            $filename = 'yolo_processed_' . $uniqueId . '.jpg';
            $originalTemp = $tmpDir . '/original_' . $uniqueId . '.jpg';
            $outputTemp = $tmpDir . '/' . $filename;
            $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

            // ✅ Descargar imagen desde Wasabi
            $wasabiDisk = Storage::disk('wasabi');
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

            if (!file_exists($originalTemp) || filesize($originalTemp) === 0) {
                throw new \Exception("Archivo descargado está vacío o no existe");
            }

            // ✅ EJECUTAR SCRIPT YOLO
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

            $timeoutSeconds = env('YOLO_TIMEOUT_SECONDS', 120);
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];

            $process = proc_open($cmd, $descriptorspec, $pipes);
            $start = time();

            while (is_resource($process)) {
                $status = proc_get_status($process);
                if (!$status['running']) break;

                if ((time() - $start) > $timeoutSeconds) {
                    proc_terminate($process, 9);
                    throw new \Exception("Timeout alcanzado al ejecutar YOLO (>{$timeoutSeconds}s)");
                }
                usleep(200000);
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                throw new \Exception("Script YOLO falló (código: {$returnCode}) - STDERR: {$stderr}");
            }

            if (!file_exists($outputTemp) || filesize($outputTemp) === 0) {
                throw new \Exception("Script YOLO no generó output válido");
            }

            // ✅ Subir imagen procesada
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));

            // ✅ Cleanup
            @unlink($originalTemp);
            @unlink($outputTemp);

            // ✅ PARSEAR JSON
            $jsonData = $this->extractJsonFromOutput($stdout);
            if (!$jsonData || !($jsonData['success'] ?? false)) {
                throw new \Exception("YOLO reportó fallo: " . ($jsonData['error'] ?? 'Error desconocido'));
            }

            // ✅ Guardar en BD
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
                'detection_confidence' => $jsonData['confidence'] ?? null,
                'processing_method' => 'yolo_segmentation',
                'algorithm_version' => 'yolo_v8_segmentation',
            ]);
            $image->analysisResult()->save($analysis);

            $image->update(['status' => 'processed']);
            $image->load(['processedImage', 'analysisResult']);

            Log::info("✅ YOLO exitoso para imagen {$image->id}");
            return $image;

        } catch (\Throwable $e) {
            Log::warning("⚠️ YOLO falló para imagen {$image->id}: " . $e->getMessage());
            // ✅ Limpiar archivos temporales en caso de error
            if (isset($originalTemp)) @unlink($originalTemp);
            if (isset($outputTemp)) @unlink($outputTemp);
            return null; // ✅ Retornar null para activar fallback
        }
    }

    /**
     * ✅ ESTRATEGIA FALLBACK: Método mejorado
     */
    /**
     * ✅ ESTRATEGIA FALLBACK: Método mejorado CORREGIDO
     */
    private function processWithImprovedFallback(Image $image, $batchId = null): Image | null
    {
        try {
            Log::info("🔄 Iniciando procesamiento con método mejorado para imagen {$image->id}");

            // ✅ CONFIGURACIÓN FALLBACK
            $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
            $scriptPath = storage_path('app/scripts/process_image_improved.py');

            // Verificar script
            if (!file_exists($scriptPath)) {
                throw new \Exception("Script mejorado no encontrado: {$scriptPath}");
            }

            $project = $image->project;
            $filas = $project?->cell_count ?? env('DEFAULT_PANEL_ROWS', 10);
            $columnas = $project?->column_count ?? env('DEFAULT_PANEL_COLUMNS', 6);

            // ✅ PATHS TEMPORALES
            $tmpDir = storage_path('app/tmp');
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            $uniqueId = uniqid('improved_' . $image->id . '_', true);
            $filename = 'improved_processed_' . $uniqueId . '.jpg';
            $originalTemp = $tmpDir . '/original_' . $uniqueId . '.jpg';
            $outputTemp = $tmpDir . '/' . $filename;
            $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

            // ✅ Descargar imagen
            $wasabiDisk = Storage::disk('wasabi');
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

            if (!file_exists($originalTemp) || filesize($originalTemp) === 0) {
                throw new \Exception("Archivo descargado está vacío o no existe");
            }

            // ✅ EJECUTAR SCRIPT MEJORADO
            $cmd = sprintf(
                '"%s" "%s" "%s" "%s" --filas %d --columnas %d',
                $pythonPath,
                $scriptPath,
                $originalTemp,
                $outputTemp,
                $filas,
                $columnas
            );

            Log::debug("🔧 Ejecutando comando mejorado: {$cmd}");

            $descriptorspec = [
                0 => ["pipe", "r"],  // stdin
                1 => ["pipe", "w"],  // stdout
                2 => ["pipe", "w"]   // stderr
            ];

            $process = proc_open($cmd, $descriptorspec, $pipes);
            $start = time();
            $timeout = 90; // Timeout para fallback

            while (is_resource($process)) {
                $status = proc_get_status($process);
                if (!$status['running']) break;

                if ((time() - $start) > $timeout) {
                    proc_terminate($process, 9);
                    throw new \Exception("Timeout en método mejorado (>{$timeout}s)");
                }
                usleep(200000);
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $returnCode = proc_close($process);

            Log::debug("🔧 Resultado comando mejorado:", [
                'return_code' => $returnCode,
                'stdout_length' => strlen($stdout),
                'stderr_length' => strlen($stderr),
                'output_file_exists' => file_exists($outputTemp),
                'output_file_size' => file_exists($outputTemp) ? filesize($outputTemp) : 0
            ]);

            if ($returnCode !== 0) {
                throw new \Exception("Script mejorado falló (código: {$returnCode}) - STDERR: {$stderr}");
            }

            if (!file_exists($outputTemp) || filesize($outputTemp) === 0) {
                throw new \Exception("Script mejorado no generó output válido");
            }

            // ✅ Subir imagen procesada
            Log::debug("⬆️ Subiendo imagen mejorada a Wasabi...");
            $wasabiDisk->put($wasabiProcessedPath, file_get_contents($outputTemp));

            if (!$wasabiDisk->exists($wasabiProcessedPath)) {
                throw new \Exception("El archivo no existe en Wasabi después de subirlo");
            }

            Log::debug("✅ Imagen mejorada subida a Wasabi correctamente");

            // ✅ Cleanup
            @unlink($originalTemp);
            @unlink($outputTemp);

            // ✅ PARSEAR JSON del método mejorado
            Log::debug("📊 Parseando output JSON del script mejorado...");

            // ✅ BUSCAR JSON EN STDOUT - MISMO MÉTODO QUE YOLO
            $jsonData = $this->extractJsonFromOutput($stdout);

            if (!$jsonData) {
                Log::warning("⚠️ No se pudo parsear JSON del método mejorado, usando valores por defecto");
                $jsonData = [
                    'integridad' => 75.0,
                    'luminosidad' => 128.0,
                    'uniformidad' => 50.0,
                    'tipo_imagen' => 'Fallback'
                ];
            }

            Log::debug("✅ JSON parseado del método mejorado:", $jsonData);

            // ✅ Guardar en BD - IGUAL QUE YOLO
            $processed = $image->processedImage ?? new ProcessedImage();
            $processed->corrected_path = $wasabiProcessedPath;
            $image->processedImage()->save($processed);

            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill([
                'rows' => $filas,
                'columns' => $columnas,
                'integrity_score' => $jsonData['integridad'] ?? 75.0,
                'luminosity_score' => $jsonData['luminosidad'] ?? 128.0,
                'uniformity_score' => $jsonData['uniformidad'] ?? 50.0,
                'processing_method' => 'improved_fallback',
                'algorithm_version' => 'opencv_improved_v2',
            ]);
            $image->analysisResult()->save($analysis);

            // ✅ CRUCIAL: Marcar como 'processed' (igual que YOLO)
            $image->update(['status' => 'processed']);
            $image->load(['processedImage', 'analysisResult']);

            Log::info("✅ Método mejorado exitoso para imagen {$image->id}");
            return $image;

        } catch (\Throwable $e) {
            $msg = "Método mejorado falló para imagen {$image->id}: " . $e->getMessage();
            Log::error("❌ " . $msg);

            // ✅ Cleanup en caso de error
            if (isset($originalTemp)) @unlink($originalTemp);
            if (isset($outputTemp)) @unlink($outputTemp);

            // ✅ NO marcar como error aquí, dejar que el job maneje el error
            return null;
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
