<?php

namespace App\Jobs;

use App\Mail\ImagesProcessedMail;
use App\Models\AnalysisBatch;
use App\Models\ImageAnalysisResult;
use App\Models\ProcessedImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessBulkImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $imageIds,
        public ?string $notifyEmail = null,
        public ?int $batchId = null
    ) {}

    public function handle()
    {
        $images = ProcessedImage::with('image')->whereIn('image_id', $this->imageIds)->get();
        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        foreach ($images as $processed) {
            $image = $processed->image;
            $inputPath = storage_path("app/public/" . $processed->corrected_path);
            if (!file_exists($inputPath)) continue;

            try {
                $response = Http::withHeaders([
                    'Prediction-Key' => env('AZURE_PREDICTION_KEY'),
                    'Content-Type' => 'application/octet-stream',
                ])->withBody(
                    file_get_contents($inputPath),
                    'application/octet-stream'
                )->post(env('AZURE_PREDICTION_FULL_ENDPOINT'));

                if ($response->successful()) {
                    $json = $response->json();
                    $mapping = [
                        'Microgrietas' => 'microcracks_count',
                        'Fingers' => 'finger_interruptions_count',
                        'Black Edges' => 'black_edges_count',
                        'Intensidad' => 'cells_with_different_intensity',
                    ];

                    $counts = [];
                    foreach ($json['predictions'] as $prediction) {
                        $tag = $prediction['tagName'];
                        if (isset($mapping[$tag])) {
                            $field = $mapping[$tag];
                            $counts[$field] = ($counts[$field] ?? 0) + 1;
                        }
                    }

                    $analysis = $image->analysisResult ?? new ImageAnalysisResult();
                    $analysis->fill($counts);
                    $image->analysisResult()->save($analysis);

                    $processed->ai_response_json = json_encode($json);
                    $processed->save();

                    $image->update(['is_processed' => true]);

                    // 🔁 Actualizar progreso del batch
                    if ($batch) $batch->increment('processed_images');
                }

            } catch (\Throwable $e) {
                Log::error("❌ Exception processing image {$image->id}", [
                    'msg' => $e->getMessage()
                ]);
            }
        }

        // ✅ Si terminó este job, comprobar si ya se completó todo el batch
        if ($batch && $batch->processed_images >= $batch->total_images) {
            $batch->update(['status' => 'done']);
            if ($this->notifyEmail) {
                Mail::to($this->notifyEmail)->send(new ImagesProcessedMail($batch->total_images));
            }
        }
    }
}
