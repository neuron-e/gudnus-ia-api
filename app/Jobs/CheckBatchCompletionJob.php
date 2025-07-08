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

class CheckBatchCompletionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 5;

    public function __construct(
        public int $batchId,
        public ?string $notifyEmail = null,
        public int $checkAttempt = 1
    ) {}

    public function handle()
    {
        $batch = AnalysisBatch::find($this.batchId);
        if (!$batch) {
            Log::error("❌ Batch {$this->batchId} no encontrado para verificación");
            return;
        }

        $totalProcessed = $batch->processed_images;
        $totalExpected = $batch->total_images;

        Log::info("🔍 Verificando batch {$batch->id}: {$totalProcessed}/{$totalExpected} procesadas (intento {$this->checkAttempt})");

        if ($totalProcessed >= $totalExpected) {
            // ✅ Batch completado
            $batch->update(['status' => 'completed']);

            if ($this->notifyEmail) {
                try {
                    Mail::to($this->notifyEmail)->send(new ImagesProcessedMail($totalProcessed));
                    Log::info("📧 Email de notificación enviado a: {$this->notifyEmail}");
                } catch (\Throwable $e) {
                    Log::warning("⚠️ No se pudo enviar email de notificación: " . $e->getMessage());
                }
            }

            Log::info("🎉 Batch de análisis IA {$batch->id} completado: {$totalProcessed} imágenes procesadas");
        } else if ($this->checkAttempt < 10) {
            // ✅ Aún no completado, reprogramar verificación
            $nextDelay = min($this->checkAttempt * 30, 300); // Max 5 minutos

            CheckBatchCompletionJob::dispatch($this->batchId, $this->notifyEmail, $this->checkAttempt + 1)
                ->delay(now()->addSeconds($nextDelay));

            Log::info("⏳ Batch {$batch->id} aún no completado, verificando en {$nextDelay}s");
        } else {
            // ✅ Demasiados intentos, marcar como completado parcialmente
            $status = $totalProcessed > 0 ? 'completed_with_errors' : 'failed';
            $batch->update(['status' => $status]);

            Log::warning("⚠️ Batch {$batch->id} marcado como {$status} después de 10 verificaciones");

            if ($this->notifyEmail && $totalProcessed > 0) {
                try {
                    Mail::to($this->notifyEmail)->send(new ImagesProcessedMail($totalProcessed));
                } catch (\Throwable $e) {
                    Log::warning("⚠️ No se pudo enviar email de notificación: " . $e->getMessage());
                }
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ CheckBatchCompletionJob falló para batch {$this->batchId}: " . $exception->getMessage());
    }
}
