<?php

namespace App\Jobs;

use App\Models\Folder;
use App\Models\Image;
use App\Models\ImageBatch;
use App\Jobs\ProcessImageImmediatelyJob;
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

    public function __construct(
        public int $projectId,
        public array $mapping,
        public string $tempPath,
        public int $batchId
    ) {}

    public function handle()
    {
        // ✅ VERIFICACIÓN: Este log debe aparecer para confirmar que el código se está ejecutando
        Log::info("🚀🚀🚀 CÓDIGO ACTUALIZADO SE ESTÁ EJECUTANDO - VERSIÓN 2.0 🚀🚀🚀");

        $batch = ImageBatch::find($this->batchId);
        if (!$batch) {
            Log::error("❌ No se encontró el batch: {$this->batchId}");
            return;
        }

        $asignadas = 0;
        $errores = 0;
        $errorMessages = [];

        try {
            $batch->update(['status' => 'processing']);

            Log::info("📊 Total entradas en mapping: " . count($this->mapping));

            // ✅ Filtrar entradas con imagen null PRIMERO
            $mappingConImagenes = array_filter($this->mapping, function($asignacion) {
                return !empty($asignacion['imagen']) && $asignacion['imagen'] !== null;
            });

            Log::info("📊 Entradas con imágenes válidas: " . count($mappingConImagenes));
            Log::info("📊 Entradas filtradas (sin imagen): " . (count($this->mapping) - count($mappingConImagenes)));

            // ✅ CORREGIR: Actualizar el total del batch con el número real de imágenes
            $batch->update(['total' => count($mappingConImagenes)]);

            if (empty($mappingConImagenes)) {
                throw new \Exception("No hay asignaciones con imágenes válidas para procesar");
            }

            // ✅ Mapear archivos disponibles en el ZIP
            $archivosDisponibles = $this->obtenerArchivosDelZip($this->tempPath);

            Log::info("📁 Archivos encontrados en ZIP: " . count($archivosDisponibles));
            Log::info("📋 Archivos: " . implode(', ', array_keys($archivosDisponibles)));

            // ✅ NUEVO: Verificar entorno de procesamiento de imágenes
            $this->verificarEntornoProcesamiento();

            foreach ($mappingConImagenes as $asignacion) {
                $imagenPath = trim($asignacion['imagen']); // "BRUTOS/DSC00001.JPG"
                $nombreImagen = basename($imagenPath); // "DSC00001.JPG"
                $moduloPath = trim($asignacion['modulo']);

                Log::info("🔍 Procesando: $imagenPath -> $moduloPath");

                // ✅ Buscar el archivo de imagen
                $archivoEncontrado = $this->buscarArchivo($imagenPath, $nombreImagen, $archivosDisponibles);

                if (!$archivoEncontrado) {
                    $errorMessages[] = "No se encontró la imagen: $nombreImagen (buscada como: $imagenPath)";
                    $errores++;
                    continue;
                }

                Log::info("✅ Archivo encontrado: $archivoEncontrado");

                // ✅ Verificar que es un archivo válido
                if (!is_file($archivoEncontrado)) {
                    $errorMessages[] = "El elemento '$nombreImagen' no es un archivo válido";
                    $errores++;
                    continue;
                }

                // ✅ Buscar la carpeta del módulo
                $folder = $this->buscarCarpetaModulo($moduloPath);

                if (!$folder) {
                    $errorMessages[] = "No se encontró el módulo para: $moduloPath";
                    $errores++;
                    continue;
                }

                Log::info("✅ Módulo encontrado: {$folder->name} (ID: {$folder->id})");

                try {
                    // Leer contenido de la imagen
                    $imageContent = file_get_contents($archivoEncontrado);

                    if ($imageContent === false) {
                        throw new \Exception("Error al leer el archivo: $nombreImagen");
                    }

                    // ✅ 1. Guardar imagen original
                    $image = $folder->storeImage($imageContent, $nombreImagen);

                    // ✅ 2. Procesar imagen (recorte con Python) - PERO SIN análisis IA
                    try {
                        $processedImage = app(\App\Services\ImageProcessingService::class)->process($image);

                        if (!$processedImage || $processedImage->status === 'error') {
                            Log::warning("⚠️ Recorte automático falló para $nombreImagen, quedará para recorte manual");
                        } else {
                            Log::info("✅ Imagen recortada automáticamente: $nombreImagen");
                        }
                    } catch (\Exception $e) {
                        Log::warning("⚠️ Error en recorte automático para $nombreImagen: " . $e->getMessage());
                        // No es crítico, la imagen queda para recorte manual
                    }

                    $asignadas++;
                    Log::info("✅ Imagen procesada (guardada + recorte): $nombreImagen -> {$folder->name}");

                    $batch->update(['processed' => $asignadas]);

                } catch (\Exception $e) {
                    Log::error("❌ Error asignando $nombreImagen: " . $e->getMessage());
                    $errores++;
                    $errorMessages[] = "Error asignando imagen: $nombreImagen - " . $e->getMessage();
                    $batch->update(['errors' => $errores]);
                }
            }

            // ✅ Determinar estado final
            if ($errores === 0) {
                $finalStatus = 'completed';
            } elseif ($asignadas > 0) {
                $finalStatus = 'completed_with_errors';
            } else {
                $finalStatus = 'failed';
            }

            $batch->update([
                'processed' => $asignadas,
                'errors' => $errores,
                'status' => $finalStatus,
                'error_messages' => $errorMessages
            ]);

            // Limpiar directorio temporal
            if (File::exists($this->tempPath)) {
                File::deleteDirectory($this->tempPath);
            }

            Log::info("🎉 Procesamiento completado - Asignadas: $asignadas, Errores: $errores, Estado: $finalStatus");

        } catch (\Throwable $e) {
            Log::error("❌ Error crítico en HandleZipMappingJob: " . $e->getMessage());
            $batch->update([
                'status' => 'failed',
                'error_messages' => array_merge($errorMessages ?? [], ["Error crítico: " . $e->getMessage()])
            ]);
        }
    }

    /**
     * Obtener todos los archivos de imagen del ZIP extraído
     */
    private function obtenerArchivosDelZip(string $tempPath): array
    {
        $archivos = [];

        if (!File::exists($tempPath)) {
            Log::error("❌ Directorio temporal no existe: $tempPath");
            return $archivos;
        }

        $files = File::allFiles($tempPath);

        foreach ($files as $file) {
            $extension = strtolower($file->getExtension());

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'bmp', 'gif', 'webp'])) {
                $absolutePath = $file->getPathname();
                $relativePath = str_replace($tempPath . DIRECTORY_SEPARATOR, '', $absolutePath);
                $relativePath = str_replace('\\', '/', $relativePath);
                $fileName = $file->getBasename();

                // Mapear tanto el nombre como la ruta relativa
                $archivos[$fileName] = $absolutePath;
                $archivos[$relativePath] = $absolutePath;

                Log::debug("📁 Mapeado: $fileName y $relativePath -> $absolutePath");
            }
        }

        return $archivos;
    }

    /**
     * Buscar un archivo de imagen usando diferentes estrategias
     */
    private function buscarArchivo(string $imagenPath, string $nombreImagen, array $archivosDisponibles): ?string
    {
        // 1. Buscar por ruta completa
        if (isset($archivosDisponibles[$imagenPath])) {
            return $archivosDisponibles[$imagenPath];
        }

        // 2. Buscar por nombre de archivo
        if (isset($archivosDisponibles[$nombreImagen])) {
            return $archivosDisponibles[$nombreImagen];
        }

        // 3. Buscar case-insensitive
        foreach ($archivosDisponibles as $nombre => $ruta) {
            if (strtolower($nombre) === strtolower($imagenPath) ||
                strtolower($nombre) === strtolower($nombreImagen)) {
                return $ruta;
            }
        }

        return null;
    }

    /**
     * ✅ NUEVO: Verificar que el entorno de procesamiento está configurado correctamente
     */
    private function verificarEntornoProcesamiento(): void
    {
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');
        $tmpDir = storage_path('app/tmp');

        Log::info("🔍 Verificando entorno de procesamiento...");
        Log::info("🐍 Python path: $pythonPath");
        Log::info("📜 Script path: $scriptPath");
        Log::info("📁 Temp dir: $tmpDir");

        // Verificar Python
        $pythonExists = file_exists($pythonPath) || (shell_exec("which python3") !== null);
        Log::info("🐍 Python disponible: " . ($pythonExists ? "SÍ" : "NO"));

        if (!$pythonExists) {
            Log::warning("⚠️ Python no encontrado. Verifique PYTHON_PATH en .env");
        }

        // Verificar script
        $scriptExists = file_exists($scriptPath);
        Log::info("📜 Script existe: " . ($scriptExists ? "SÍ" : "NO"));

        if (!$scriptExists) {
            Log::warning("⚠️ Script Python no encontrado en: $scriptPath");
        }

        // Verificar directorio temporal
        $tmpDirExists = is_dir($tmpDir);
        $tmpDirWritable = $tmpDirExists && is_writable($tmpDir);

        Log::info("📁 Directorio tmp existe: " . ($tmpDirExists ? "SÍ" : "NO"));
        Log::info("✏️ Directorio tmp escribible: " . ($tmpDirWritable ? "SÍ" : "NO"));

        if (!$tmpDirExists) {
            Log::warning("⚠️ Creando directorio temporal: $tmpDir");
            try {
                mkdir($tmpDir, 0755, true);
                Log::info("✅ Directorio temporal creado");
            } catch (\Exception $e) {
                Log::error("❌ Error creando directorio temporal: " . $e->getMessage());
            }
        }
    }

    /**
     * Buscar carpeta de módulo navegando la jerarquía
     */
    private function buscarCarpetaModulo(string $path): ?Folder
    {
        // Ejemplo: "MÁS DE GIL / CT CT 1 / INV INV 1 / CB CB 1 / TRK TRK 1 / STR STR 1 / MOD MOD 1"
        $partes = array_map('trim', explode(' / ', $path));

        if (count($partes) < 2) {
            return null;
        }

        // Empezar desde la raíz (saltar el nombre del proyecto)
        $parentId = null;

        for ($i = 1; $i < count($partes); $i++) {
            $nombreParte = $partes[$i];

            $carpeta = Folder::where('project_id', $this->projectId)
                ->where('parent_id', $parentId)
                ->where('name', $nombreParte)
                ->first();

            if (!$carpeta) {
                Log::warning("❌ No se encontró: '$nombreParte' bajo parent_id: $parentId");
                return null;
            }

            $parentId = $carpeta->id;
        }

        // La última carpeta debe ser un módulo
        $moduloFinal = Folder::find($parentId);
        if ($moduloFinal && $moduloFinal->type === 'modulo') {
            return $moduloFinal;
        }

        return null;
    }
}
