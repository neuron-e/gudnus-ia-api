<?php

namespace App\Jobs;

use App\Models\UnifiedBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CancelBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutos
    public $tries = 1;     // Solo un intento

    public function __construct(public int $batchId)
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $batch = UnifiedBatch::find($this->batchId);

        if (!$batch) {
            Log::error("❌ CancelBatchJob: Batch {$this->batchId} no encontrado");
            return;
        }

        if ($batch->status !== 'cancelling') {
            Log::info("ℹ️ CancelBatchJob: Batch {$this->batchId} ya no está en estado 'cancelling' (estado actual: {$batch->status})");
            return;
        }

        $batch->logInfo("Finalizando cancelación después de tiempo de gracia");

        try {
            // ✅ Verificar si aún hay jobs activos después del tiempo de gracia
            $actualActiveJobs = $this->countActiveJobsInQueues($batch);

            if ($actualActiveJobs === 0) {
                // ✅ Todos los jobs terminaron naturalmente
                $batch->update([
                    'status' => 'cancelled',
                    'active_jobs' => 0,
                    'completed_at' => now(),
                    'last_activity_at' => now()
                ]);

                $batch->logInfo("Cancelación completada limpiamente - todos los jobs terminaron naturalmente");

            } else {
                // ⚠️ Aún hay jobs activos, forzar terminación
                Log::warning("⚠️ Forzando terminación de {$actualActiveJobs} jobs restantes del batch {$this->batchId}");

                $forcedCount = $this->forceTerminateJobs($batch);

                $batch->update([
                    'status' => 'cancelled',
                    'active_jobs' => 0,
                    'completed_at' => now(),
                    'last_activity_at' => now(),
                    'cancellation_reason' => ($batch->cancellation_reason ?? 'user_cancelled') . ' (forced_termination)'
                ]);

                $batch->logInfo("Cancelación completada con terminación forzada de {$forcedCount} jobs");
            }

            // ✅ Programar limpieza de archivos temporales
            CleanupBatchFilesJob::dispatch($this->batchId)
                ->delay(now()->addMinutes(2))
                ->onQueue('maintenance');

        } catch (\Throwable $e) {
            $batch->logError("Error en cancelación: " . $e->getMessage());

            // En caso de error, marcar como cancelado de todas formas
            $batch->update([
                'status' => 'cancelled',
                'active_jobs' => 0,
                'completed_at' => now(),
                'last_error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ✅ Contar jobs realmente activos en las colas
     */
    private function countActiveJobsInQueues(UnifiedBatch $batch): int
    {
        // TODO: Implementar en Fase 2 - conteo real de jobs en Redis
        // Por ahora, simulamos que no hay jobs activos después del tiempo de gracia

        $batch->logInfo("Verificando jobs activos en colas (PLACEHOLDER - siempre retorna 0)");
        return 0;
    }

    /**
     * ✅ Forzar terminación de jobs restantes
     */
    private function forceTerminateJobs(UnifiedBatch $batch): int
    {
        // TODO: Implementar en Fase 2 - terminación forzada real
        // Por ahora, simulamos que se terminaron todos

        $batch->logInfo("Forzando terminación de jobs (PLACEHOLDER)");
        return $batch->active_jobs;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("❌ CancelBatchJob FAILED para batch {$this->batchId}: " . $exception->getMessage());

        // En caso de fallo total, marcar batch como cancelado de emergencia
        $batch = UnifiedBatch::find($this->batchId);
        if ($batch && $batch->status === 'cancelling') {
            $batch->update([
                'status' => 'cancelled',
                'active_jobs' => 0,
                'completed_at' => now(),
                'last_error' => 'Cancelación falló: ' . $exception->getMessage()
            ]);
        }
    }
}
