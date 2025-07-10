<?php

// ✅ COMANDO ARTISAN: app/Console/Commands/CleanupExpiredReports.php

namespace App\Console\Commands;

use App\Models\ReportGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredReports extends Command
{
    protected $signature = 'reports:cleanup {--dry-run : Solo mostrar qué se eliminaría}';
    protected $description = 'Limpiar reportes de PDF expirados';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $expiredReports = ReportGeneration::where('expires_at', '<', now())
            ->where('status', 'completed')
            ->get();

        if ($expiredReports->isEmpty()) {
            $this->info('No se encontraron reportes expirados.');
            return;
        }

        $this->info("Encontrados {$expiredReports->count()} reportes expirados:");

        foreach ($expiredReports as $report) {
            $this->line("- ID: {$report->id}, Proyecto: {$report->project->name}, Expiró: {$report->expires_at}");

            if (!$dryRun) {
                $report->deleteFiles();
                $report->delete();
            }
        }

        if ($dryRun) {
            $this->warn('Ejecución de prueba: no se eliminó nada. Ejecuta sin --dry-run para confirmar.');
        } else {
            $this->info("✅ Se limpiaron {$expiredReports->count()} reportes expirados.");
            Log::info("Limpieza automática de reportes: {$expiredReports->count()} reportes eliminados");
        }
    }
}
