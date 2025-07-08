<?php

namespace App\Jobs;

use App\Mail\ImagesProcessedMail;
use App\Models\AnalysisBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessBulkImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // ✅ Reducido a 5 minutos por chunk
    public $tries = 3; // ✅ Más reintentos
    public $maxExceptions = 3;

    // ✅ Backoff exponencial para reintentos
    public function backoff(): array
    {
        return [30, 120, 300]; // 30s, 2min, 5min
    }

    public function __construct(
        public array $imageIds, // ✅ Ahora maneja chunks más pequeños
        public ?string $notifyEmail = null,
        public ?int $batchId = null,
        public int $chunkIndex = 0,
        public int $totalChunks = 1
    ) {}

    public function handle()
    {
        $chunkSize = count($this->imageIds);
        Log::info("🤖 Iniciando chunk {$this->chunkIndex}/{$this->totalChunks} de análisis IA para {$chunkSize} imágenes");

        $batch = $this->batchId ? AnalysisBatch::find($this->batchId) : null;

        if ($batch) {
            $batch->touch();
            Log::info("🚀 Procesando chunk de análisis IA {$batch->id}");
        }

        // ✅ En lugar de procesar todas aquí, despachar jobs individuales
        foreach ($this->imageIds as $index => $imageId) {
            // ✅ Delay progresivo para evitar rate limiting
            $delay = $index * 2; // 2 segundos entre cada job

            ProcessImageImmediatelyJob::dispatch($imageId, $this->batchId)
                ->delay(now()->addSeconds($delay))
                ->onQueue('analysis');
        }

        Log::info("✅ Despachados {$chunkSize} jobs individuales para chunk {$this->chunkIndex}");

        // ✅ Solo enviar email en el último chunk
        if ($this->chunkIndex === $this->totalChunks - 1 && $this->notifyEmail && $batch) {
            // ✅ Programar verificación de completado después de procesar todos los jobs
            $estimatedTime = count($this->imageIds) * 3; // 3 segundos por imagen estimado
            CheckBatchCompletionJob::dispatch($batch->id, $this->notifyEmail)
                ->delay(now()->addSeconds($estimatedTime + 60)); // +1 minuto de buffer
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ ProcessBulkImagesJob chunk {$this->chunkIndex} falló: " . $exception->getMessage());

        if ($this->batchId) {
            $batch = AnalysisBatch::find($this->batchId);
            if ($batch && $this->chunkIndex === $this->totalChunks - 1) {
                // Solo marcar como fallido si es el último chunk
                $batch->update(['status' => 'failed']);
                Log::error("❌ Batch de análisis IA {$batch->id} marcado como fallido");
            }
        }
    }
}
