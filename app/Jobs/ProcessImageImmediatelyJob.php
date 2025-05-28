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

        $inputPath = storage_path("app/public/{$image->original_path}");
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

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !Storage::disk('public')->exists($relativeProcessed)) {
            Log::error("⚠️ Error procesando imagen ID {$image->id}", [
                'cmd' => $cmd,
                'returnCode' => $returnCode,
                'output' => $output,
            ]);
            $image->update(['status' => 'error']); // 🔥 Marca como error
            return;
        }

        $jsonData = json_decode(implode('', $output), true);
        if (json_last_error() !== JSON_ERROR_NONE || !$jsonData) {
            Log::error("❌ Error parseando JSON en imagen ID {$image->id}", ['output' => $output]);
            $image->update(['status' => 'error']);
            return;
        }

        // Guardar imagen procesada
        $processed = $image->processedImage ?? new ProcessedImage();
        $processed->corrected_path = $relativeProcessed;
        $image->processedImage()->save($processed);

        // Guardar análisis
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

        Log::info("✅ Imagen ID {$image->id} procesada correctamente");
    }
}
