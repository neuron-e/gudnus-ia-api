<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBulkImagesJob;
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
                'error' => 'La imagen no ha sido tratada. Sube una imagen recortada antes de procesar con IA.'
            ], 400);
        }

        $correctedPath = $image->processedImage->corrected_path;

        if (!Storage::disk('wasabi')->exists($correctedPath)) {
            return response()->json([
                'error' => 'La imagen recortada no existe en el sistema.'
            ], 404);
        }

        $imageContent = Storage::disk('wasabi')->get($correctedPath);

        Log::info('Azure Prediction Request', [
            'file_path' => $correctedPath,
        ]);

        $response = Http::withHeaders([
            'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
            'Content-Type' => 'application/octet-stream',
        ])->withBody(
            $imageContent,
            'application/octet-stream'
        )->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

        if (!$response->successful()) {
            Log::error("Azure prediction failed for image {$image->id}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return response()->json([
                'error' => 'Fallo en la predicción con Azure',
                'status' => $response->status(),
            ], 500);
        }

        $json = $response->json();
        Log::info("Azure prediction response for image {$image->id}", ['response' => $json]);

        // Mapeo de etiquetas a campos
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

        // Guardar resultados
        $analysis = $image->analysisResult ?? new \App\Models\ImageAnalysisResult();
        $analysis->fill($counts);
        $image->analysisResult()->save($analysis);

        $image->processedImage->ai_response_json = json_encode($json);
        $image->processedImage->save();

        $image->update(['is_processed' => true]);

        Log::info("✅ Análisis IA guardado para imagen {$image->id}", $counts);

        return response()->json([
            'ok' => true,
            'analysis_result' => $analysis
        ]);
    }

    public function processBulk(Request $request, Project $project)
    {
        $request->validate([
            'image_ids' => 'required|array',
            'image_ids.*' => 'integer|exists:processed_images,image_id',
            'email' => 'nullable|email'
        ]);

        $images = ProcessedImage::whereIn('image_id', $request->image_ids)
            ->whereNull('ai_response_json')
            ->whereNotNull('corrected_path')
            ->get();

        if ($images->isEmpty()) {
            return response()->json(['ok' => false, 'msg' => 'No hay imágenes válidas para procesar'], 400);
        }

        // 🔸 Crear registro persistente para seguimiento
        $batch = AnalysisBatch::create([
            'project_id' => $project->id,
            'image_ids' => json_encode($images->pluck('image_id')->toArray()),
            'total_images' => $images->count(),
            'processed_images' => 0,
            'status' => 'processing',
        ]);

        // 🔁 Agrupar en lotes de 64 y encolar
        $chunks = $images->chunk(64);
        foreach ($chunks as $chunk) {
            ProcessBulkImagesJob::dispatch(
                $chunk->pluck('image_id')->toArray(),
                $request->email,
                $batch->id
            );
        }

        return response()->json([
            'ok' => true,
            'msg' => 'Procesamiento encolado correctamente',
            'batch_id' => $batch->id
        ]);
    }

}
