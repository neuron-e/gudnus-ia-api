<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkImagesJob;
use App\Jobs\ProcessImageImmediatelyJob;
use App\Models\AnalysisBatch;
use App\Models\Image;
use App\Models\ProcessedImage;
use App\Models\ImageAnalysisResult;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProcessedImageController extends Controller
{
    public function process(Request $request, $imageId)
    {
        $image = Image::with('processedImage')->findOrFail($imageId);

        if (!$image->processedImage || !$image->processedImage->corrected_path) {
            return response()->json([
                'error' => 'La imagen no ha sido recortada. Recorta la imagen antes de procesar con IA.'
            ], 400);
        }

        $correctedPath = $image->processedImage->corrected_path;

        if (!Storage::disk('wasabi')->exists($correctedPath)) {
            return response()->json([
                'error' => 'La imagen recortada no existe en el sistema.'
            ], 404);
        }

        try {
            $imageContent = Storage::disk('wasabi')->get($correctedPath);

            Log::info('🤖 Azure Prediction Request para imagen individual', [
                'image_id' => $image->id,
                'file_path' => $correctedPath,
            ]);

            $response = Http::timeout(60)
                ->withHeaders([
                    'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                    'Content-Type' => 'application/octet-stream',
                ])
                ->withBody($imageContent, 'application/octet-stream')
                ->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

            if (!$response->successful()) {
                Log::error("❌ Azure prediction failed para imagen {$image->id}", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json([
                    'error' => 'Fallo en la predicción con Azure',
                    'status' => $response->status(),
                ], 500);
            }

            $json = $response->json();
            Log::info("✅ Azure prediction response para imagen {$image->id}", ['response' => $json]);

            // ✅ Mapeo de etiquetas a campos
            $mapping = [
                'Microgrietas' => 'microcracks_count',
                'Fingers' => 'finger_interruptions_count',
                'Black Edges' => 'black_edges_count',
                'Intensidad' => 'cells_with_different_intensity',
            ];

            $counts = [];
            foreach ($json['predictions'] ?? [] as $prediction) {
                $tag = $prediction['tagName'];
                if (isset($mapping[$tag])) {
                    $field = $mapping[$tag];
                    $counts[$field] = ($counts[$field] ?? 0) + 1;
                }
            }

            // ✅ Guardar resultados
            $analysis = $image->analysisResult ?? new ImageAnalysisResult();
            $analysis->fill($counts);
            $image->analysisResult()->save($analysis);

            $image->processedImage->ai_response_json = json_encode($json);
            $image->processedImage->save();

            $image->update(['is_processed' => true]);

            Log::info("✅ Análisis IA guardado para imagen {$image->id}", $counts);

            return response()->json([
                'ok' => true,
                'analysis_result' => $analysis,
                'message' => 'Imagen procesada con IA exitosamente'
            ]);

        } catch (\Throwable $e) {
            Log::error("❌ Error procesando imagen {$image->id} con IA: " . $e->getMessage());

            return response()->json([
                'error' => 'Error interno procesando con IA: ' . $e->getMessage()
            ], 500);
        }
    }


    public function processBulk(Request $request, Project $project)
    {
        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'integer|exists:images,id',
            'email' => 'nullable|email'
        ]);

        // ✅ Filtrar solo imágenes que tienen imagen procesada y no están ya analizadas
        $validImages = ProcessedImage::whereIn('image_id', $request->image_ids)
            ->whereNotNull('corrected_path')
            ->whereHas('image', function($q) {
                $q->where('is_processed', false);
            })
            ->get();

        if ($validImages->isEmpty()) {
            return response()->json([
                'ok' => false,
                'msg' => 'No hay imágenes válidas para procesar. Asegúrate de que las imágenes estén recortadas y no hayan sido analizadas previamente.'
            ], 400);
        }

        $totalImages = $validImages->count();
        $imageIds = $validImages->pluck('image_id')->toArray();

        Log::info("🤖 Iniciando análisis IA masivo", [
            'project_id' => $project->id,
            'total_requested' => count($request->image_ids),
            'valid_images' => $totalImages,
            'notify_email' => $request->email
        ]);

        // ✅ Crear batch de análisis
        $batch = AnalysisBatch::create([
            'project_id' => $project->id,
            'image_ids' => json_encode($imageIds),
            'total_images' => $totalImages,
            'processed_images' => 0,
            'status' => 'processing',
        ]);

        // ✅ Dividir en chunks de tamaño configurable
        $chunkSize = $this->getOptimalChunkSize($totalImages);
        $chunks = array_chunk($imageIds, $chunkSize);
        $totalChunks = count($chunks);

        Log::info("✅ Dividiendo en {$totalChunks} chunks de máximo {$chunkSize} imágenes");

        // ✅ Despachar cada chunk con delay progresivo
        foreach ($chunks as $index => $chunk) {
            $delay = $index * 30; // 30 segundos entre chunks para evitar sobrecarga

            ProcessBulkImagesJob::dispatch(
                $chunk,
                $request->email,
                $batch->id,
                $index + 1, // chunkIndex (1-based)
                $totalChunks
            )
                ->delay(now()->addSeconds($delay))
                ->onQueue('analysis');
        }

        Log::info("✅ Análisis IA encolado correctamente", [
            'batch_id' => $batch->id,
            'chunks' => $totalChunks,
            'total_images' => $totalImages,
            'chunk_size' => $chunkSize
        ]);

        return response()->json([
            'ok' => true,
            'msg' => "Análisis IA encolado para {$totalImages} imágenes en {$totalChunks} lotes. Recibirás una notificación cuando termine.",
            'batch_id' => $batch->id,
            'total_images' => $totalImages,
            'chunks' => $totalChunks,
            'estimated_time_minutes' => $this->estimateProcessingTime($totalImages)
        ]);
    }

    /**
     * ✅ Determinar el tamaño óptimo de chunk según el total de imágenes
     */
    private function getOptimalChunkSize(int $totalImages): int
    {
        if ($totalImages <= 50) {
            return 10; // Chunks pequeños para lotes pequeños
        } elseif ($totalImages <= 200) {
            return 25; // Chunks medianos
        } elseif ($totalImages <= 500) {
            return 50; // Chunks grandes para lotes muy grandes
        } else {
            return 75; // Chunks extra grandes para lotes masivos
        }
    }

    /**
     * ✅ Estimar tiempo de procesamiento en minutos
     */
    private function estimateProcessingTime(int $totalImages): int
    {
        // Estimación: 30 segundos por imagen (incluyendo delays y reintentos)
        $estimatedSeconds = $totalImages * 30;
        return max(5, ceil($estimatedSeconds / 60)); // Mínimo 5 minutos
    }

    /**
     * ✅ Procesar una sola imagen de forma asíncrona (usando job)
     */
    public function processAsync(Request $request, $imageId)
    {
        $image = Image::with('processedImage')->findOrFail($imageId);

        if (!$image->processedImage || !$image->processedImage->corrected_path) {
            return response()->json([
                'error' => 'La imagen no ha sido recortada. Recorta la imagen antes de procesar con IA.'
            ], 400);
        }

        if ($image->is_processed) {
            return response()->json([
                'ok' => true,
                'msg' => 'La imagen ya ha sido procesada con IA.'
            ]);
        }

        // ✅ Crear batch individual
        $batch = AnalysisBatch::create([
            'project_id' => $image->project_id,
            'image_ids' => json_encode([$imageId]),
            'total_images' => 1,
            'processed_images' => 0,
            'status' => 'processing',
        ]);

        // ✅ Despachar job individual
        ProcessImageImmediatelyJob::dispatch($imageId, $batch->id)
            ->onQueue('analysis');

        Log::info("✅ Análisis IA individual encolado para imagen {$imageId}", [
            'batch_id' => $batch->id
        ]);

        return response()->json([
            'ok' => true,
            'msg' => 'Análisis IA encolado. El proceso se completará en segundo plano.',
            'batch_id' => $batch->id
        ]);
    }


}
