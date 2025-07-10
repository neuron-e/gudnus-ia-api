<?php

use App\Models\ReportGeneration;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*Schedule::command('reports:cleanup')
    ->dailyAt('02:00')
    ->timezone('Europe/Madrid')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Log::error('Failed to cleanup expired reports');
    })
    ->onSuccess(function () {
        \Log::info('Successfully cleaned up expired reports');
    });

// Health check de reportes cada hora
Schedule::call(function () {
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

// Limpieza semanal más agresiva
Schedule::command('reports:cleanup')
    ->weekly()
    ->sundays()
    ->at('03:00')
    ->timezone('Europe/Madrid');*/
