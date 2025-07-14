<?php

namespace App\Providers;

use App\Jobs\CleanupTemporaryFilesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

class CleanupServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // ✅ Solo configurar scheduler si estamos en consola
        if ($this->app->runningInConsole()) {
            $this->configureScheduler();
        }
    }

    /**
     * 🕐 Configurar tareas programadas
     */
    private function configureScheduler(): void
    {
        $schedule = $this->app->make(Schedule::class);

        // 🧹 LIMPIEZA AUTOMÁTICA DE ARCHIVOS TEMPORALES

        // ✅ Limpieza ligera cada 2 horas
        $schedule->call(function () {
            dispatch(new CleanupTemporaryFilesJob());
        })
            ->everyTwoHours()
            ->name('cleanup-temp-light')
            ->withoutOverlapping(120) // No solapar por 2 horas
            ->onOneServer(); // Solo en un servidor si hay múltiples

        // ✅ Limpieza completa diaria a las 2:00 AM
        $schedule->command('app:cleanup-temp-files --force')
            ->dailyAt('02:00')
            ->name('cleanup-temp-full')
            ->withoutOverlapping(240) // No solapar por 4 horas
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/cleanup.log'));

        // ✅ Verificación de espacio crítico cada hora
        $schedule->call(function () {
            $this->checkCriticalDiskSpace();
        })
            ->hourly()
            ->name('disk-space-check')
            ->skip(function () {
                // Skip si ya hay una limpieza en curso
                return cache()->has('cleanup-in-progress');
            });

        // ✅ Limpieza de emergencia si espacio < 3GB
        $schedule->call(function () {
            $freeSpace = $this->getCurrentFreeSpace();
            if ($freeSpace < 3) {
                Log::critical("🚨 ESPACIO CRÍTICO: {$freeSpace}GB - Ejecutando limpieza de emergencia");
                dispatch(new CleanupTemporaryFilesJob())->onQueue('high');
            }
        })
            ->everyFifteenMinutes()
            ->name('emergency-cleanup')
            ->when(function () {
                return $this->getCurrentFreeSpace() < 5; // Solo verificar si < 5GB
            });

        // ✅ Limpieza de logs antiguos
        $schedule->command('log:clear --days=30')
            ->weekly()
            ->sundays()
            ->at('03:00')
            ->name('log-cleanup');

        // ✅ Limpieza automática de reportes expirados
        $schedule->call(function () {
            $this->cleanupExpiredReports();
        })
            ->everySixHours()
            ->name('cleanup-expired-reports');

        // ✅ Mover archivos grandes a Wasabi automáticamente
        $schedule->call(function () {
            $this->moveFilesToWasabiAutomatically();
        })
            ->daily()
            ->at('04:00')
            ->name('move-files-to-wasabi')
            ->onOneServer();
    }

    /**
     * 🚨 Verificar espacio crítico en disco
     */
    private function checkCriticalDiskSpace(): void
    {
        $freeSpace = $this->getCurrentFreeSpace();

        if ($freeSpace < 2) {
            // ✅ Espacio CRÍTICO - Acción inmediata
            Log::critical("🚨 ESPACIO CRÍTICO: {$freeSpace}GB - Limpieza de emergencia");

            cache()->put('cleanup-in-progress', true, now()->addHours(2));

            // Ejecutar limpieza inmediata en cola de alta prioridad
            dispatch(new CleanupTemporaryFilesJob())
                ->onQueue('high')
                ->delay(now()->addSeconds(10));

            // Notificar administradores
            $this->notifyAdministrators("ESPACIO CRÍTICO: {$freeSpace}GB libres");

        } elseif ($freeSpace < 5) {
            // ✅ Espacio BAJO - Advertencia
            Log::warning("⚠️ ESPACIO BAJO: {$freeSpace}GB libres");

            // Limpieza preventiva si no se ha hecho en las últimas 4 horas
            if (!cache()->has('preventive-cleanup-' . date('Y-m-d-H'))) {
                cache()->put('preventive-cleanup-' . date('Y-m-d-H'), true, now()->addHours(4));
                dispatch(new CleanupTemporaryFilesJob())->delay(now()->addMinutes(5));
            }
        }
    }

    /**
     * 🗑️ Limpiar reportes expirados automáticamente
     */
    private function cleanupExpiredReports(): void
    {
        try {
            $expiredReports = \App\Models\ReportGeneration::expired()->get();
            $cleaned = 0;

            foreach ($expiredReports as $report) {
                $report->deleteFiles();
                $report->delete();
                $cleaned++;
            }

            if ($cleaned > 0) {
                Log::info("🗑️ Limpieza automática: {$cleaned} reportes expirados eliminados");
            }
        } catch (\Exception $e) {
            Log::error("Error en limpieza de reportes expirados: " . $e->getMessage());
        }
    }

    /**
     * 📤 Mover archivos grandes a Wasabi automáticamente
     */
    private function moveFilesToWasabiAutomatically(): void
    {
        try {
            // ✅ Mover reportes grandes a Wasabi
            $largeReports = \App\Models\ReportGeneration::where('status', 'completed')
                ->where('storage_type', '!=', 'wasabi')
                ->whereNotNull('file_path')
                ->where('created_at', '>', now()->subDays(7)) // Solo reportes recientes
                ->get();

            $moved = 0;
            foreach ($largeReports as $report) {
                if ($report->moveToWasabiIfNeeded()) {
                    $moved++;
                }
            }

            if ($moved > 0) {
                Log::info("📤 Movidos {$moved} reportes a Wasabi automáticamente");
            }

            // ✅ Limpiar archivos temporales antiguos
            $this->cleanupOldTempFiles();

        } catch (\Exception $e) {
            Log::error("Error moviendo archivos a Wasabi: " . $e->getMessage());
        }
    }

    /**
     * 🧹 Limpiar archivos temporales muy antiguos
     */
    private function cleanupOldTempFiles(): void
    {
        $storagePath = storage_path('app');
        $patterns = ['temp_*', 'tmp/*', '*.tmp'];
        $cleaned = 0;

        foreach ($patterns as $pattern) {
            $files = glob("{$storagePath}/{$pattern}");

            foreach ($files as $file) {
                // ✅ Solo eliminar archivos/directorios más antiguos de 24 horas
                if (filemtime($file) < strtotime('-24 hours')) {
                    if (is_dir($file)) {
                        \Illuminate\Support\Facades\File::deleteDirectory($file);
                    } else {
                        @unlink($file);
                    }
                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0) {
            Log::debug("🧹 Limpiados {$cleaned} archivos temporales antiguos");
        }
    }

    /**
     * 📊 Obtener espacio libre actual
     */
    private function getCurrentFreeSpace(): float
    {
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        return $freeBytes ? round($freeBytes / 1024 / 1024 / 1024, 2) : 0;
    }

    /**
     * 📧 Notificar administradores sobre problemas críticos
     */
    private function notifyAdministrators(string $message): void
    {
        try {
            // ✅ Log crítico
            Log::critical($message);

            // ✅ Enviar email si está configurado
            $adminEmail = env('ADMIN_EMAIL');
            if ($adminEmail) {
                Mail::raw(
                    "ALERTA CRÍTICA DEL SERVIDOR:\n\n{$message}\n\nFecha: " . now()->toDateTimeString(),
                    function ($mail) use ($adminEmail, $message) {
                        $mail->to($adminEmail)
                            ->subject('[CRÍTICO] ' . $message);
                    }
                );
            }

            // ✅ Notificación Slack si está configurado
            $slackWebhook = env('SLACK_WEBHOOK_URL');
            if ($slackWebhook) {
                Http::post($slackWebhook, [
                    'text' => "🚨 ALERTA CRÍTICA: {$message}",
                    'channel' => '#alerts',
                    'username' => 'ServerBot'
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error enviando notificación crítica: " . $e->getMessage());
        }
    }
}
