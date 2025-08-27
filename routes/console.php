<?php

use App\Jobs\CleanupExpiredDownloadsJob;
use App\Models\ReportGeneration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================================================
// TAREAS AUTOMÁTICAS PARA ARCHIVOS GRANDES Y LIMPIEZA - LARAVEL 12
// ============================================================================

// ✅ CONFIGURACIÓN CORRECTA PARA LARAVEL 12
return function (Schedule $schedule) {

    // Limpiar downloads expirados cada día a las 2 AM
    $schedule->job(new CleanupExpiredDownloadsJob)
        ->timezone('Europe/Madrid')
        ->dailyAt('02:00');

    // ✅ LIMPIEZA DE ARCHIVOS MISTERIOSOS WASABI - Diaria a las 3:00 AM
    $schedule->command('wasabi:investigate --clean --days=7')
        ->dailyAt('03:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping()
        ->runInBackground()
        ->onFailure(function () {
            \Log::error('Failed to cleanup mysterious Wasabi files');
        })
        ->onSuccess(function () {
            \Log::info('Successfully cleaned up mysterious Wasabi files');
        });

    // ✅ LIMPIEZA DE ARCHIVOS TEMPORALES - Cada 6 horas
    $schedule->command('cleanup:temp-files --force')
        ->cron('0 */6 * * *')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping()
        ->runInBackground()
        ->onFailure(function () {
            \Log::error('Failed to cleanup temporary files');
        });

    // ✅ LIMPIEZA DE REPORTES EXPIRADOS - Diaria a las 2:00 AM
    $schedule->command('reports:cleanup')
        ->dailyAt('02:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping()
        ->onFailure(function () {
            \Log::error('Failed to cleanup expired reports');
        })
        ->onSuccess(function () {
            \Log::info('Successfully cleaned up expired reports');
        });

    // ✅ LIMPIEZA DE TOKENS PÚBLICOS EXPIRADOS - Diaria a las 2:30 AM
    $schedule->command('tokens:cleanup')
        ->dailyAt('02:30')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping()
        ->onFailure(function () {
            \Log::error('Failed to cleanup expired tokens');
        })
        ->onSuccess(function () {
            \Log::info('Successfully cleaned up expired tokens');
        });

    // ✅ VERIFICAR REPORTES COLGADOS - Cada hora
    $schedule->call(function () {
        $stuckReports = ReportGeneration::where('status', 'processing')
            ->where('created_at', '<', now()->subHours(2))
            ->count();

        if ($stuckReports > 0) {
            \Log::warning("Found {$stuckReports} reports stuck for more than 2 hours");

            // Marcar como fallidos después de 4 horas
            ReportGeneration::where('status', 'processing')
                ->where('created_at', '<', now()->subHours(4))
                ->update([
                    'status' => 'failed',
                    'error_message' => 'Timeout: El proceso tardó más de 4 horas'
                ]);
        }
    })->hourly();

    // ✅ MONITOREO DE ESPACIO EN DISCO - Cada hora
    $schedule->call(function () {
        $freeSpace = disk_free_space(storage_path('app'));
        $freeGB = round($freeSpace / 1024 / 1024 / 1024, 2);

        if ($freeGB < 10) {
            \Log::critical("CRITICAL: Only {$freeGB}GB free space remaining");

            // Ejecutar limpieza de emergencia
            Artisan::call('cleanup:temp-files', ['--force' => true]);
            Artisan::call('wasabi:investigate', ['--clean' => true, '--days' => 3]);
        } elseif ($freeGB < 20) {
            \Log::warning("WARNING: Only {$freeGB}GB free space remaining");
        }
    })->hourly();

    // ✅ VERIFICAR Y REINICIAR HORIZON SI ESTÁ COLGADO - Cada 30 minutos
    $schedule->call(function () {
        // Verificar si Horizon está corriendo
        $output = '';
        $returnVar = 0;
        exec('php artisan horizon:status 2>&1', $output, $returnVar);

        $isRunning = false;
        foreach ($output as $line) {
            if (strpos($line, 'running') !== false) {
                $isRunning = true;
                break;
            }
        }

        if (!$isRunning) {
            \Log::warning('Horizon not running, attempting restart');
            Artisan::call('horizon:terminate');
            sleep(10);
            // Iniciar en background
            exec('nohup php artisan horizon > storage/logs/horizon.log 2>&1 &');
        }
    })->cron('*/30 * * * *');

    // ✅ LIMPIEZA SEMANAL MÁS AGRESIVA - Domingos a las 3:00 AM
    $schedule->command('wasabi:investigate --clean --days=3')
        ->weekly()
        ->sundays()
        ->at('03:00')
        ->timezone('Europe/Madrid')
        ->withoutOverlapping();

    // ✅ LIMPIEZA DE LOGS ANTIGUOS - Semanal
    $schedule->call(function () {
        $logPath = storage_path('logs');
        $command = "find {$logPath} -name '*.log' -type f -mtime +30 -delete";
        exec($command);
        \Log::info('Cleaned up old log files (>30 days)');
    })->weekly();

};
