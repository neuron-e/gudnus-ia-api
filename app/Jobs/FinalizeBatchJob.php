<?php

namespace App\Jobs;

use App\Models\ImageBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FinalizeBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 1;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $batch = ImageBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ FinalizeBatchJob: Batch no encontrado: {$this->batchId}");
            return;
        }

        Log::info("🔍 FinalizeBatchJob iniciado para batch {$this->batchId}");

        $expected = $batch->expected_total ?? 0;
        $processed = $batch->processed;
        $errors = $batch->errors ?? 0;
        $totalActual = $processed + $errors;

        Log::debug("📊 Estado actual: {$processed} procesadas, {$errors} errores, total esperado: {$expected}");

        if ($totalActual < $expected) {
            Log::warning("⚠️ El batch {$this->batchId} aún no ha finalizado: {$totalActual}/{$expected}");
            return; // No finalizar aún
        }

        if ($errors === 0 && $processed > 0) {
            $finalStatus = 'completed';
        } elseif ($processed > 0) {
            $finalStatus = 'completed_with_errors';
        } else {
            $finalStatus = 'failed';
        }

        $batch->update([
            'status' => $finalStatus,
        ]);

        if ($batch->temp_path && File::exists($batch->temp_path)) {
            File::deleteDirectory($batch->temp_path);
            Log::info("🧹 Directorio temporal eliminado (Finalize): {$batch->temp_path}");
        }

        Log::info("🎉 FinalizeBatchJob: Batch {$this->batchId} finalizado con estado: {$finalStatus}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ FinalizeBatchJob fallido para batch {$this->batchId}: " . $exception->getMessage());
    }
}
