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
        if (!$image) return;

        app(\App\Services\ImageProcessingService::class)->process($image, $this->batchId);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ El procesamiento de imagen ID {$this->imageId} falló: " . $exception->getMessage());

        if ($this->batchId) {
            $batch = \App\Models\ImageBatch::find($this->batchId);
            if ($batch) {
                $batch->increment('errors');
                $batch->update([
                    'error_messages' => array_merge($batch->error_messages ?? [], [$exception->getMessage()])
                ]);
            }
        }
    }
}
