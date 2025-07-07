<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Services\ImageProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class HandleZipMappingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 2;

    public function __construct(
        public int $projectId,
        public array $mapping,
        public string $zipPath,  // ✅ CORREGIDO: Ahora recibe la ruta del ZIP
        public int $batchId
    ) {}

    public function handle()
    {
        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado");
            return;
        }

        Log::info("🚀 INICIANDO HandleZipMappingJob para batch {$this->batchId} con " . count($this->mapping) . " asignaciones");
        Log::info("📦 ZIP recibido: {$this->zipPath}");

        $batch->update(['status' => 'processing']);
        $tempPath = null;

        try {
            // ✅ PASO 1: Verificar que el ZIP existe
            if (!file_exists($this->zipPath)) {
                throw new \Exception("Archivo ZIP no encontrado: {$this->zipPath}");
            }

            $zipSize = filesize($this->zipPath);
            Log::info("✅ ZIP verificado: {$zipSize} bytes");

            // ✅ PASO 2: Crear directorio temporal dentro del job
            $tempPath = storage_path("app/temp_extract_" . $this->batchId . "_" . time());

            if (File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
            }

            File::makeDirectory($tempPath, 0755, true);
            Log::info("📁 Directorio temporal creado: {$tempPath}");

            // ✅ PASO 3: Extraer ZIP dentro del job
            Log::info("📦 Extrayendo ZIP...");

            $zip = new \ZipArchive;
            $result = $zip->open($this->zipPath);

            if ($result !== true) {
                throw new \Exception("No se pudo abrir el ZIP (código: {$result})");
            }

            $extracted = $zip->extractTo($tempPath);
            $numFiles = $zip->numFiles;
            $zip->close();

            if (!$extracted) {
                throw new \Exception("Error extrayendo el ZIP");
            }

            Log::info("✅ ZIP extraído: {$numFiles} archivos en {$tempPath}");

            // ✅ PASO 4: Verificar que se extrajeron archivos
            $extractedFiles = File::allFiles($tempPath);
            Log::info("📋 Archivos extraídos: " . count($extractedFiles));

            if (empty($extractedFiles)) {
                throw new \Exception("No se encontraron archivos después de extraer el ZIP");
            }

            // ✅ Mostrar algunos archivos extraídos para debug
            foreach (array_slice($extractedFiles, 0, 5) as $file) {
                Log::info("  - " . $file->getFilename() . " (" . $file->getSize() . " bytes)");
            }

            // ✅ PASO 5: Procesar solo la primera imagen para debug
            $primeraAsignacion = $this->mapping[0];
            Log::info("🧪 PROCESANDO SOLO LA PRIMERA IMAGEN:");

            $nombreImagen = basename($primeraAsignacion['imagen']);
            $moduloPath = trim($primeraAsignacion['modulo']);
            $fullImagePath = $tempPath . '/' . $nombreImagen;

            Log::info("📝 Datos primera imagen:", [
                'imagen' => $nombreImagen,
                'modulo_path' => $moduloPath,
                'full_image_path' => $fullImagePath,
                'file_exists' => file_exists($fullImagePath),
                'is_file' => is_file($fullImagePath),
                'file_size' => file_exists($fullImagePath) ? filesize($fullImagePath) : 0
            ]);

            $asignadas = 0;
            $errores = 0;
            $errorMessages = [];

            if (!file_exists($fullImagePath)) {
                // ✅ Si no existe con el nombre exacto, buscar variaciones
                Log::info("🔍 Archivo no encontrado con nombre exacto, buscando variaciones...");

                $foundFiles = [];
                foreach ($extractedFiles as $file) {
                    $foundFiles[] = $file->getFilename();
                }

                Log::info("📋 Todos los archivos extraídos:");
                foreach ($foundFiles as $fileName) {
                    Log::info("  - {$fileName}");
                }

                // Buscar archivo similar (sin case sensitivity)
                $targetLower = strtolower($nombreImagen);
                foreach ($foundFiles as $fileName) {
                    if (strtolower($fileName) === $targetLower) {
                        $fullImagePath = $tempPath . '/' . $fileName;
                        Log::info("✅ Encontrado archivo con nombre similar: {$fileName}");
                        break;
                    }
                }
            }

            if (!file_exists($fullImagePath)) {
                $errores++;
                $errorMessages[] = "Archivo no encontrado: {$nombreImagen}";
                Log::error("❌ FALLO: Archivo no encontrado después de búsqueda exhaustiva");
            } else {
                Log::info("✅ Archivo encontrado, continuando procesamiento...");

                // ✅ Buscar carpeta
                Log::info("🔍 Buscando carpeta: '{$moduloPath}'");

                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    Log::error("❌ Carpeta no encontrada: '{$moduloPath}'");

                    // Debug de carpetas disponibles
                    $availableFolders = Folder::where('project_id', $this->projectId)
                        ->limit(10)
                        ->get(['id', 'name', 'full_path']);

                    Log::info("📂 Carpetas disponibles en el proyecto:");
                    foreach ($availableFolders as $af) {
                        Log::info("  - ID {$af->id}: '{$af->full_path}'");
                    }

                    $errores++;
                    $errorMessages[] = "Carpeta no encontrada: {$moduloPath}";
                } else {
                    Log::info("✅ Carpeta encontrada: ID {$folder->id}");

                    try {
                        // ✅ Leer contenido del archivo
                        $imageContent = file_get_contents($fullImagePath);
                        if ($imageContent === false) {
                            throw new \Exception("No se pudo leer el archivo");
                        }

                        Log::info("📖 Contenido leído: " . strlen($imageContent) . " bytes");

                        // ✅ Limpiar imágenes existentes
                        foreach ($folder->images as $existing) {
                            if (Storage::disk('wasabi')->exists($existing->original_path)) {
                                Storage::disk('wasabi')->delete($existing->original_path);
                            }
                            if ($existing->processedImage && Storage::disk('wasabi')->exists($existing->processedImage->corrected_path)) {
                                Storage::disk('wasabi')->delete($existing->processedImage->corrected_path);
                            }
                            $existing->processedImage()?->delete();
                            $existing->analysisResult()?->delete();
                            $existing->delete();
                        }

                        // ✅ Crear imagen
                        $image = $folder->storeImage($imageContent, $nombreImagen);
                        Log::info("📷 Imagen creada: ID {$image->id}");

                        // ✅ Verificar en Wasabi
                        $wasabiExists = Storage::disk('wasabi')->exists($image->original_path);
                        Log::info("☁️ Imagen en Wasabi: " . ($wasabiExists ? 'SÍ' : 'NO'));

                        if ($wasabiExists) {
                            // ✅ Procesar con ImageProcessingService
                            Log::info("⚙️ Procesando con ImageProcessingService...");

                            $imageProcessingService = app(ImageProcessingService::class);
                            $processedImage = $imageProcessingService->process($image, $this->batchId);

                            if ($processedImage && $processedImage->status === 'processed') {
                                $asignadas++;
                                Log::info("✅ ÉXITO: Imagen procesada correctamente");
                            } else {
                                $errores++;
                                $status = $processedImage ? ($processedImage->status ?? 'no_status') : 'null';
                                Log::error("❌ ImageProcessingService falló (status: {$status})");
                                $errorMessages[] = "ImageProcessingService falló: {$status}";
                            }
                        } else {
                            $errores++;
                            Log::error("❌ Imagen no se subió correctamente a Wasabi");
                            $errorMessages[] = "Error subiendo a Wasabi";
                        }

                    } catch (\Exception $e) {
                        $errores++;
                        Log::error("❌ Error procesando imagen: " . $e->getMessage());
                        $errorMessages[] = "Error procesando: " . $e->getMessage();
                    }
                }
            }

            // ✅ Simular resto de imágenes como errores para debug
            $errores += (count($this->mapping) - 1);
            Log::info("📊 Simulando resto como errores para debug");

            // ✅ Finalizar batch
            $this->finalizeBatch($batch, $asignadas, $errores, $errorMessages);

        } catch (\Throwable $e) {
            Log::error("❌ ERROR CRÍTICO: " . $e->getMessage());
            Log::error("❌ Stack trace: " . $e->getTraceAsString());
            $batch->update([
                'status' => 'failed',
                'error_messages' => ["Error crítico: " . $e->getMessage()]
            ]);
        } finally {
            // ✅ Cleanup
            if ($tempPath && File::exists($tempPath)) {
                File::deleteDirectory($tempPath);
                Log::info("🧹 Directorio temporal eliminado: {$tempPath}");
            }

            // ✅ Cleanup del ZIP
            if (file_exists($this->zipPath)) {
                @unlink($this->zipPath);
                Log::info("🧹 ZIP eliminado: {$this->zipPath}");
            }
        }
    }

    private function finalizeBatch($batch, $asignadas, $errores, $errorMessages)
    {
        $batch->refresh();

        Log::info("🔍 ESTADO FINAL:", [
            'batch_id' => $this->batchId,
            'processed_db' => $batch->processed,
            'errors_db' => $batch->errors ?? 0,
            'local_asignadas' => $asignadas,
            'local_errores' => $errores
        ]);

        $totalProcesadas = $batch->processed;
        $totalErrores = $batch->errors ?? 0;

        if ($totalErrores === 0 && $totalProcesadas > 0) {
            $finalStatus = 'completed';
        } elseif ($totalProcesadas > 0) {
            $finalStatus = 'completed_with_errors';
        } else {
            $finalStatus = 'failed';
        }

        $batch->update([
            'status' => $finalStatus,
            'error_messages' => $errorMessages
        ]);

        Log::info("🎉 FINALIZADO: Batch {$this->batchId} - Status: {$finalStatus}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ Job FAILED para batch {$this->batchId}: " . $exception->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
