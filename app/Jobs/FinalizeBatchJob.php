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

        // ✅ Si ya está en estado final, no hacer nada
        if (in_array($batch->status, ['completed', 'completed_with_errors', 'failed'])) {
            Log::info("ℹ️ Batch {$this->batchId} ya está en estado final: {$batch->status}");
            $this->cleanupTempPath($batch);
            return;
        }

        $expected = $batch->expected_total ?? $batch->total;
        $processed = $batch->processed;
        $errors = $batch->errors ?? 0;
        $totalDone = $processed + $errors;

        Log::info("📊 Estado batch {$this->batchId}: {$processed} procesadas, {$errors} errores, {$expected} esperadas");

        // ✅ Verificar si hay jobs pendientes aún corriendo
        $stillRunning = $this->checkIfJobsStillRunning($batch);

        if ($stillRunning && $totalDone < $expected) {
            Log::info("⏳ Batch {$this->batchId} aún tiene jobs corriendo. Reprogramando verificación en 5 minutos.");
            // Reprogramar para verificar nuevamente en 5 minutos
            dispatch(new FinalizeBatchJob($this->batchId))->delay(now()->addMinutes(5));
            return;
        }

        // ✅ Determinar estado final
        if ($totalDone >= $expected || $stillRunning === false) {
            if ($errors === 0 && $processed > 0) {
                $finalStatus = 'completed';
            } elseif ($processed > 0) {
                $finalStatus = 'completed_with_errors';
            } else {
                $finalStatus = 'failed';
            }

            // ✅ Actualizar estado final
            $batch->update([
                'status' => $finalStatus,
                'processed' => min($processed, $expected), // ✅ Asegurar que no exceda lo esperado
            ]);

            $this->cleanupTempPath($batch);

            Log::info("🎉 FinalizeBatchJob: Batch {$this->batchId} finalizado con estado: {$finalStatus} ({$processed} procesadas, {$errors} errores)");
        } else {
            Log::warning("⚠️ Batch {$this->batchId} no cumple criterios de finalización. Total: {$totalDone}/{$expected}, Jobs corriendo: " . ($stillRunning ? 'Sí' : 'No'));
        }
    }

    /**
     * ✅ Verificar si aún hay jobs corriendo para este batch
     */
    private function checkIfJobsStillRunning(ImageBatch $batch): bool
    {
        // ✅ Verificar por tiempo transcurrido desde la última actualización
        $minutesSinceUpdate = $batch->updated_at->diffInMinutes(now());

        // Si ha pasado más de 15 minutos sin actualizaciones, asumir que no hay jobs corriendo
        if ($minutesSinceUpdate > 15) {
            Log::info("⏰ Batch {$this->batchId}: {$minutesSinceUpdate} minutos sin actualizaciones. Asumiendo que no hay jobs activos.");
            return false;
        }

        // Si se actualizó recientemente, probablemente hay jobs corriendo
        return $minutesSinceUpdate < 5;
    }

    /**
     * ✅ Limpiar directorio temporal
     */
    private function cleanupTempPath(ImageBatch $batch): void
    {
        if ($batch->temp_path && File::exists($batch->temp_path)) {
            try {
                File::deleteDirectory($batch->temp_path);
                Log::info("🧹 Directorio temporal eliminado: {$batch->temp_path}");

                // Limpiar el campo temp_path
                $batch->update(['temp_path' => null]);
            } catch (\Exception $e) {
                Log::warning("⚠️ No se pudo eliminar directorio temporal {$batch->temp_path}: " . $e->getMessage());
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ FinalizeBatchJob fallido para batch {$this->batchId}: " . $exception->getMessage());

        $batch = ImageBatch::find($this->batchId);
        if ($batch && $batch->status === 'processing') {
            $batch->update(['status' => 'failed']);
        }
    }
}
