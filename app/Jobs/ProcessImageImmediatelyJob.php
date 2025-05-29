<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\ImageAnalysisResult;
use App\Models\ImageBatch;
use App\Models\ProcessedImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessImageImmediatelyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $imageId, public int|null $batchId = null) {}

    public function handle(): void
    {
        $image = Image::find($this->imageId);
        if (!$image || !$image->original_path) {
            Log::error("❌ Imagen no encontrada para procesar (ID: {$this->imageId})");
            return;
        }

        if ($this->batchId) {
            ImageBatch::find($this->batchId)?->increment('processed');
        }

       /* $inputPath = storage_path("app/public/{$image->original_path}");
        $filename = 'aligned_' . Str::random(8) . '.jpg';
        $relativeProcessed = "projects/{$image->project_id}/images/processed/{$filename}";
        $outputPath = storage_path("app/public/{$relativeProcessed}");

        Log::info("🔧 Procesando imagen ID {$image->id}: $inputPath → $outputPath");

        if (!file_exists($inputPath)) {
            Log::error("❌ Archivo no existe: $inputPath");
            return;
        }

        $pythonPath = env('PYTHON_PATH', 'C:\\Python312\\python.exe');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');
        $cmd = "\"$pythonPath\" \"$scriptPath\" \"$inputPath\" \"$outputPath\"";

        exec($cmd, $output, $returnCode);*/

        $wasabiDisk = Storage::disk('wasabi');
        $pythonPath = env('PYTHON_PATH', '/usr/bin/python3');
        $scriptPath = storage_path('app/scripts/process_image_improved.py');

        // --- Prepara paths temporales
        $filename = 'aligned_' . Str::random(8) . '.jpg';
        $originalTemp = storage_path('app/tmp/original_' . basename($image->original_path));
        $outputTemp = storage_path("app/tmp/{$filename}");

        $wasabiProcessedPath = "projects/{$image->project_id}/images/processed/{$filename}";

        // --- Descargar original desde Wasabi
        try {
            file_put_contents($originalTemp, $wasabiDisk->get($image->original_path));
        } catch (\Throwable $e) {
            Log::error("❌ No se pudo descargar la imagen original desde Wasabi", [
                'image_id' => $image->id,
                'path' => $image->original_path,
                'error' => $e->getMessage(),
            ]);
            $image->update(['status' => 'error']);
            return;
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
            return;
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
            return;
        }

        // --- Limpiar archivos temporales
        @unlink($originalTemp);
        @unlink($outputTemp);

        // --- Parsear salida del script
        $jsonData = json_decode(implode('', $output), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            Log::error("❌ Error parseando JSON en imagen ID {$image->id}", ['output' => $output]);
            $image->update(['status' => 'error']);
            return;
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

        Log::info("✅ Imagen ID {$image->id} procesada y almacenada en Wasabi correctamente");
    }
}
