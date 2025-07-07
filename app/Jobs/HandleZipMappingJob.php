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
        public string $tempPath,
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

        $batch->update(['status' => 'processing']);
        $asignadas = 0;
        $errores = 0;
        $errorMessages = [];

        try {
            // ✅ Procesar solo la PRIMERA imagen para debug máximo
            $primeraAsignacion = $this->mapping[0];
            Log::info("🧪 MODO ULTRA DEBUG: Procesando SOLO la primera imagen");

            $nombreImagen = basename($primeraAsignacion['imagen']);
            $moduloPath = trim($primeraAsignacion['modulo']);
            $fullImagePath = $this->tempPath . '/' . $nombreImagen;

            Log::info("📝 DATOS PRIMERA IMAGEN:", [
                'imagen' => $nombreImagen,
                'modulo_path' => $moduloPath,
                'full_image_path' => $fullImagePath,
                'file_exists' => file_exists($fullImagePath),
                'is_file' => is_file($fullImagePath),
                'file_size' => file_exists($fullImagePath) ? filesize($fullImagePath) : 0
            ]);

            // ✅ Verificaciones básicas
            if (!file_exists($fullImagePath)) {
                Log::error("❌ FALLO 1: Archivo no existe: $fullImagePath");
                $errores++;
                $errorMessages[] = "Archivo no existe: $nombreImagen";
            } elseif (!is_file($fullImagePath)) {
                Log::error("❌ FALLO 2: No es archivo: $fullImagePath");
                $errores++;
                $errorMessages[] = "No es archivo: $nombreImagen";
            } else {
                Log::info("✅ PASO 1: Archivo existe y es válido");

                // ✅ Buscar carpeta
                Log::info("🔍 PASO 2: Buscando carpeta...");
                Log::info("🔍 Criterios búsqueda:", [
                    'project_id' => $this->projectId,
                    'full_path' => $moduloPath
                ]);

                $folder = Folder::where('project_id', $this->projectId)
                    ->where('full_path', $moduloPath)
                    ->first();

                if (!$folder) {
                    Log::error("❌ FALLO 3: Carpeta no encontrada");

                    // ✅ DEBUGGING EXHAUSTIVO de carpetas
                    $totalFolders = Folder::where('project_id', $this->projectId)->count();
                    Log::info("📊 Total carpetas en proyecto: {$totalFolders}");

                    $allPaths = Folder::where('project_id', $this->projectId)
                        ->get(['id', 'name', 'full_path'])
                        ->take(10);

                    Log::info("📂 Primeras 10 carpetas del proyecto:");
                    foreach ($allPaths as $path) {
                        Log::info("  - ID {$path->id}: '{$path->full_path}' (name: '{$path->name}')");
                    }

                    $errores++;
                    $errorMessages[] = "Carpeta no encontrada: $moduloPath";
                } else {
                    Log::info("✅ PASO 2: Carpeta encontrada - ID {$folder->id}");

                    try {
                        // ✅ PASO 3: Leer contenido del archivo
                        Log::info("📖 PASO 3: Leyendo contenido del archivo...");

                        $imageContent = file_get_contents($fullImagePath);
                        if ($imageContent === false) {
                            throw new \Exception("file_get_contents falló");
                        }

                        $contentLength = strlen($imageContent);
                        Log::info("✅ PASO 3: Contenido leído - {$contentLength} bytes");

                        // ✅ PASO 4: Limpiar imágenes existentes
                        Log::info("🧹 PASO 4: Limpiando imágenes existentes...");
                        $existingCount = $folder->images->count();

                        foreach ($folder->images as $existing) {
                            Log::info("🗑️ Eliminando imagen existente ID {$existing->id}");

                            if (Storage::disk('wasabi')->exists($existing->original_path)) {
                                Storage::disk('wasabi')->delete($existing->original_path);
                                Log::info("  - Eliminado de Wasabi: {$existing->original_path}");
                            }

                            if ($existing->processedImage && Storage::disk('wasabi')->exists($existing->processedImage->corrected_path)) {
                                Storage::disk('wasabi')->delete($existing->processedImage->corrected_path);
                                Log::info("  - Eliminado processed de Wasabi");
                            }

                            $existing->processedImage()?->delete();
                            $existing->analysisResult()?->delete();
                            $existing->delete();
                        }

                        Log::info("✅ PASO 4: Eliminadas {$existingCount} imágenes existentes");

                        // ✅ PASO 5: Crear nueva imagen usando storeImage
                        Log::info("📷 PASO 5: Creando imagen con folder->storeImage()...");

                        // ✅ Verificar que el método existe
                        if (!method_exists($folder, 'storeImage')) {
                            throw new \Exception("Método storeImage no existe en el modelo Folder");
                        }

                        Log::info("✅ Método storeImage existe");

                        try {
                            $image = $folder->storeImage($imageContent, $nombreImagen);
                            Log::info("✅ PASO 5: Imagen creada exitosamente", [
                                'image_id' => $image->id,
                                'filename' => $image->filename,
                                'original_path' => $image->original_path,
                                'folder_id' => $image->folder_id,
                                'project_id' => $image->project_id
                            ]);

                            // ✅ PASO 6: Verificar en Wasabi
                            Log::info("☁️ PASO 6: Verificando en Wasabi...");
                            $wasabiExists = Storage::disk('wasabi')->exists($image->original_path);
                            Log::info("☁️ Imagen existe en Wasabi: " . ($wasabiExists ? 'SÍ' : 'NO'));

                            if (!$wasabiExists) {
                                throw new \Exception("Imagen no se subió a Wasabi correctamente");
                            }

                            // ✅ PASO 7: Instanciar ImageProcessingService
                            Log::info("⚙️ PASO 7: Instanciando ImageProcessingService...");

                            try {
                                $imageProcessingService = app(ImageProcessingService::class);
                                Log::info("✅ ImageProcessingService instanciado correctamente");

                                // ✅ PASO 8: Llamar al proceso
                                Log::info("🔧 PASO 8: Llamando a process()...");
                                Log::info("🔧 Parámetros:", [
                                    'image_id' => $image->id,
                                    'batch_id' => $this->batchId
                                ]);

                                $processedImage = $imageProcessingService->process($image, $this->batchId);

                                Log::info("🔧 PASO 8: process() retornó:", [
                                    'returned_null' => $processedImage === null,
                                    'returned_image_id' => $processedImage ? $processedImage->id : 'null',
                                    'returned_status' => $processedImage ? ($processedImage->status ?? 'no_status') : 'null'
                                ]);

                                if ($processedImage && $processedImage->status === 'processed') {
                                    $asignadas++;
                                    Log::info("✅ ÉXITO COMPLETO: Imagen procesada correctamente");
                                } else {
                                    $errores++;
                                    $status = $processedImage ? ($processedImage->status ?? 'no_status') : 'null_returned';
                                    Log::error("❌ FALLO 8: ImageProcessingService no procesó correctamente (status: {$status})");
                                    $errorMessages[] = "ImageProcessingService falló: status {$status}";
                                }

                            } catch (\Exception $e) {
                                $errores++;
                                Log::error("❌ FALLO 8: Exception en ImageProcessingService: " . $e->getMessage());
                                Log::error("❌ Stack trace: " . $e->getTraceAsString());
                                $errorMessages[] = "Exception en ImageProcessingService: " . $e->getMessage();
                            }

                        } catch (\Exception $e) {
                            $errores++;
                            Log::error("❌ FALLO 5: Error en storeImage(): " . $e->getMessage());
                            Log::error("❌ Stack trace: " . $e->getTraceAsString());
                            $errorMessages[] = "Error en storeImage: " . $e->getMessage();
                        }

                    } catch (\Exception $e) {
                        $errores++;
                        Log::error("❌ FALLO GENERAL: " . $e->getMessage());
                        Log::error("❌ Stack trace: " . $e->getTraceAsString());
                        $errorMessages[] = "Error general: " . $e->getMessage();
                    }
                }
            }

            // ✅ Simular el resto de las imágenes como errores para mantener el conteo
            $errores += (count($this->mapping) - 1);
            Log::info("📊 SIMULANDO resto de imágenes como errores para mantener conteo total");

            // ✅ Finalización
            $this->finalizeBatch($batch, $asignadas, $errores, $errorMessages);

        } catch (\Throwable $e) {
            Log::error("❌ ERROR CRÍTICO en HandleZipMappingJob: " . $e->getMessage());
            Log::error("❌ Stack trace: " . $e->getTraceAsString());
            $batch->update([
                'status' => 'failed',
                'error_messages' => array_merge($errorMessages, ["Error crítico: " . $e->getMessage()])
            ]);
        } finally {
            // ✅ Cleanup
            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
                Log::info("🧹 Directorio temporal eliminado: {$this->tempPath}");
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
            'total' => $batch->total,
            'local_asignadas' => $asignadas,
            'local_errores' => $errores,
            'error_messages_count' => count($errorMessages)
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
        Log::error("❌ Stack trace: " . $exception->getTraceAsString());

        $batch = ImageBatch::find($this->batchId);
        if ($batch) {
            $batch->update(['status' => 'failed']);
        }
    }
}
