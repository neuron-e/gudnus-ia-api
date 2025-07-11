<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

class QueueManageCommand extends Command
{
    protected $signature = 'queue:manage
        {queue : Nombre de la cola (ej: analysis)}
        {--action= : clear | pause | resume | flush | retry | stats}
        {--job= : ID del job para retry (si aplica)}
        {--connection=redis : Nombre de la conexión, por defecto redis}';

    protected $description = 'Gestiona colas de Laravel profesionalmente: pausar, limpiar, reintentar, ver estado';

    public function handle()
    {
        $queue = $this->argument('queue');
        $action = $this->option('action');
        $jobId = $this->option('job');
        $connection = $this->option('connection');

        match ($action) {
            'clear' => $this->clearQueue($connection, $queue),
            'flush' => $this->flushQueue($connection, $queue),
            'pause' => $this->pauseQueue($connection, $queue),
            'resume' => $this->resumeQueue($connection, $queue),
            'retry' => $this->retryJob($jobId),
            'stats' => $this->showStats($connection, $queue),
            default => $this->error('Acción no válida. Usa: clear, flush, pause, resume, retry, stats')
        };
    }

    protected function clearQueue(string $connection, string $queue): void
    {
        $this->warn("Limpiando jobs pendientes en la cola [$queue]...");
        Artisan::call("horizon:clear $queue --force");
        $this->info("✅ Cola [$queue] vaciada");
    }

    protected function flushQueue(string $connection, string $queue): void
    {
        $this->warn("Eliminando todos los jobs en espera en [$queue]...");
        Artisan::call("queue:flush $connection --queue=$queue");
        $this->info("✅ Jobs flush ejecutado sobre [$queue]");
    }

    protected function pauseQueue(string $connection, string $queue): void
    {
        Artisan::call("queue:pause $connection --queue=$queue");
        $this->info("⏸️ Cola [$queue] pausada correctamente");
    }

    protected function resumeQueue(string $connection, string $queue): void
    {
        Artisan::call("queue:resume $connection --queue=$queue");
        $this->info("▶️ Cola [$queue] reanudada correctamente");
    }

    protected function retryJob(?string $jobId): void
    {
        if (!$jobId) {
            $this->error('Debes proporcionar --job=<ID> para reintentar un job fallido.');
            return;
        }

        Artisan::call("queue:retry $jobId");
        $this->info("🔁 Job $jobId reintentado correctamente");
    }

    protected function showStats(string $connection, string $queue): void
    {
        $pending = Redis::llen("queues:$queue");
        $delayed = Redis::zcard("queues:$queue:delayed");
        $reserved = Redis::zcard("queues:$queue:reserved");

        $this->info("📊 Estado de la cola [$queue]:");
        $this->line("  Jobs pendientes: $pending");
        $this->line("  Jobs delayed: $delayed");
        $this->line("  Jobs reservados: $reserved");
    }
}
