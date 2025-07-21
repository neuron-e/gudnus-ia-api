<?php

namespace App\Console\Commands;

use App\Helpers\PerformanceMonitor;
use App\Models\AnalysisBatch;
use App\Models\DownloadBatch;
use App\Models\ImageBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SystemMonitorCommand extends Command
{
    protected $signature = 'system:monitor
                           {--hours=24 : Horas de estadísticas a mostrar}
                           {--detailed : Mostrar información detallada}
                           {--export : Exportar métricas a archivo}';

    protected $description = 'Monitorear rendimiento del sistema optimizado para 8vCPU/32GB';

    public function handle()
    {
        $this->info("🖥️  MONITOR DEL SISTEMA - SERVIDOR OPTIMIZADO (8vCPU/32GB)");
        $this->info("════════════════════════════════════════════════════════════");

        $hours = (int) $this->option('hours');
        $detailed = $this->option('detailed');
        $export = $this->option('export');

        try {
            // ✅ INFORMACIÓN DEL SISTEMA
            $this->showSystemInfo();

            // ✅ RECURSOS ACTUALES
            $this->showCurrentResources();

            // ✅ ESTADO DE LAS COLAS
            $this->showQueueStatus();

            // ✅ ESTADÍSTICAS DE BATCHES
            $this->showBatchStatistics();

            // ✅ MÉTRICAS DE PERFORMANCE
            $this->showPerformanceMetrics($hours);

            // ✅ INFORMACIÓN DETALLADA
            if ($detailed) {
                $this->showDetailedInfo();
            }

            // ✅ ALERTAS Y RECOMENDACIONES
            $this->showAlertsAndRecommendations();

            // ✅ EXPORTAR SI SE SOLICITA
            if ($export) {
                $this->exportMetrics($hours);
            }

        } catch (\Exception $e) {
            $this->error("❌ Error en monitoreo: " . $e->getMessage());
            Log::channel('critical')->error("Error en SystemMonitorCommand: " . $e->getMessage());
        }
    }

    private function showSystemInfo(): void
    {
        $this->info("\n📊 INFORMACIÓN DEL SISTEMA");
        $this->line("──────────────────────────");

        $phpVersion = PHP_VERSION;
        $laravelVersion = app()->version();
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        $timezone = config('app.timezone');

        $this->table(
            ['Componente', 'Valor'],
            [
                ['PHP Version', $phpVersion],
                ['Laravel Version', $laravelVersion],
                ['Memory Limit', $memoryLimit],
                ['Max Execution Time', $maxExecutionTime . 's'],
                ['Timezone', $timezone],
                ['Environment', app()->environment()],
                ['Debug Mode', config('app.debug') ? 'ON' : 'OFF'],
            ]
        );
    }

    private function showCurrentResources(): void
    {
        $this->info("\n💾 RECURSOS ACTUALES");
        $this->line("─────────────────────");

        // ✅ MEMORIA
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercent = round(($memoryUsage / $memoryLimit) * 100, 1);

        // ✅ DISCO
        $storagePath = storage_path('app');
        $freeBytes = disk_free_space($storagePath);
        $totalBytes = disk_total_space($storagePath);
        $diskPercent = $totalBytes ? round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1) : 0;

        // ✅ CARGA DEL SERVIDOR
        $load = $this->getServerLoad();

        $this->table(
            ['Recurso', 'Actual', 'Máximo/Total', 'Uso %', 'Estado'],
            [
                [
                    'Memoria RAM',
                    $this->formatBytes($memoryUsage),
                    $this->formatBytes($memoryLimit),
                    $memoryPercent . '%',
                    $this->getStatusEmoji($memoryPercent, 80, 90)
                ],
                [
                    'Memoria Pico',
                    $this->formatBytes($memoryPeak),
                    $this->formatBytes($memoryLimit),
                    round(($memoryPeak / $memoryLimit) * 100, 1) . '%',
                    '📈'
                ],
                [
                    'Disco Storage',
                    $this->formatBytes($totalBytes - $freeBytes),
                    $this->formatBytes($totalBytes),
                    $diskPercent . '%',
                    $this->getStatusEmoji($diskPercent, 80, 90)
                ],
                [
                    'Load Average',
                    $load['1min'] ?? 'N/A',
                    '8.0 (8 vCPUs)',
                    $load['1min'] ? round(($load['1min'] / 8) * 100, 1) . '%' : 'N/A',
                    $this->getLoadStatusEmoji($load['1min'] ?? 0)
                ]
            ]
        );
    }

    private function showQueueStatus(): void
    {
        $this->info("\n⚡ ESTADO DE LAS COLAS");
        $this->line("─────────────────────");

        $queues = [
            'default' => 'Tareas generales',
            'images' => 'Procesamiento imágenes',
            'analysis' => 'Análisis IA Azure',
            'downloads' => 'Generación descargas',
            'zip-analysis' => 'Análisis ZIP grandes',
            'high-priority' => 'Alta prioridad'
        ];

        $queueData = [];
        foreach ($queues as $queue => $description) {
            try {
                $pending = Redis::llen("queues:{$queue}");
                $delayed = Redis::zcard("queues:{$queue}:delayed");
                $reserved = Redis::zcard("queues:{$queue}:reserved");
                $total = $pending + $delayed + $reserved;

                $queueData[] = [
                    $queue,
                    $description,
                    $pending,
                    $delayed,
                    $reserved,
                    $total,
                    $this->getQueueStatusEmoji($total)
                ];
            } catch (\Exception $e) {
                $queueData[] = [$queue, $description, 'Error', 'Error', 'Error', 'Error', '❌'];
            }
        }

        $this->table(
            ['Cola', 'Descripción', 'Pendientes', 'Delayed', 'Reservados', 'Total', 'Estado'],
            $queueData
        );
    }

    private function showBatchStatistics(): void
    {
        $this->info("\n📋 ESTADÍSTICAS DE BATCHES (Últimas 24h)");
        $this->line("───────────────────────────────────────");

        $since = now()->subDay();

        // ✅ IMAGE BATCHES
        $imageBatches = ImageBatch::where('created_at', '>=', $since)->get();
        $imageStats = [
            'total' => $imageBatches->count(),
            'completed' => $imageBatches->where('status', 'completed')->count(),
            'processing' => $imageBatches->where('status', 'processing')->count(),
            'failed' => $imageBatches->where('status', 'failed')->count(),
        ];

        // ✅ ANALYSIS BATCHES
        $analysisBatches = AnalysisBatch::where('created_at', '>=', $since)->get();
        $analysisStats = [
            'total' => $analysisBatches->count(),
            'completed' => $analysisBatches->where('status', 'completed')->count(),
            'processing' => $analysisBatches->where('status', 'processing')->count(),
            'failed' => $analysisBatches->where('status', 'failed')->count(),
        ];

        // ✅ DOWNLOAD BATCHES
        $downloadBatches = DownloadBatch::where('created_at', '>=', $since)->get();
        $downloadStats = [
            'total' => $downloadBatches->count(),
            'completed' => $downloadBatches->where('status', 'completed')->count(),
            'processing' => $downloadBatches->where('status', 'processing')->count(),
            'failed' => $downloadBatches->where('status', 'failed')->count(),
        ];

        $this->table(
            ['Tipo de Batch', 'Total', 'Completados', 'Procesando', 'Fallidos', 'Tasa Éxito'],
            [
                [
                    'Procesamiento Imágenes',
                    $imageStats['total'],
                    $imageStats['completed'],
                    $imageStats['processing'],
                    $imageStats['failed'],
                    $imageStats['total'] > 0 ? round(($imageStats['completed'] / $imageStats['total']) * 100, 1) . '%' : 'N/A'
                ],
                [
                    'Análisis IA',
                    $analysisStats['total'],
                    $analysisStats['completed'],
                    $analysisStats['processing'],
                    $analysisStats['failed'],
                    $analysisStats['total'] > 0 ? round(($analysisStats['completed'] / $analysisStats['total']) * 100, 1) . '%' : 'N/A'
                ],
                [
                    'Descargas',
                    $downloadStats['total'],
                    $downloadStats['completed'],
                    $downloadStats['processing'],
                    $downloadStats['failed'],
                    $downloadStats['total'] > 0 ? round(($downloadStats['completed'] / $downloadStats['total']) * 100, 1) . '%' : 'N/A'
                ]
            ]
        );
    }

    private function showPerformanceMetrics(int $hours): void
    {
        $this->info("\n📈 MÉTRICAS DE PERFORMANCE (Últimas {$hours}h)");
        $this->line("──────────────────────────────────────────");

        $stats = PerformanceMonitor::getPerformanceStats($hours);

        if (empty($stats)) {
            $this->warn("⚠️ No hay métricas disponibles para las últimas {$hours} horas");
            return;
        }

        $this->table(
            ['Métrica', 'Valor', 'Estado'],
            [
                ['Total Jobs Ejecutados', $stats['total_jobs'] ?? 0, '📊'],
                ['Jobs Exitosos', $stats['successful_jobs'] ?? 0, '✅'],
                ['Tasa de Éxito', ($stats['total_jobs'] > 0 ? round(($stats['successful_jobs'] / $stats['total_jobs']) * 100, 1) : 0) . '%', '📈'],
                ['Tiempo Promedio Ejecución', round($stats['avg_execution_time'] ?? 0, 1) . 's', '⏱️'],
                ['Memoria Promedio', $this->formatBytes($stats['avg_memory_usage'] ?? 0), '💾'],
                ['Requests Azure Total', $stats['azure_total_requests'] ?? 0, '🤖'],
                ['Azure Tasa Éxito', ($stats['azure_success_rate'] ?? 0) . '%', '🎯'],
                ['Azure Tiempo Respuesta', round($stats['azure_avg_response_time'] ?? 0, 2) . 's', '⚡']
            ]
        );
    }

    private function showDetailedInfo(): void
    {
        $this->info("\n🔍 INFORMACIÓN DETALLADA");
        $this->line("──────────────────────");

        // ✅ TOP PROCESOS ACTIVOS
        $this->showActiveBatches();

        // ✅ ANÁLISIS DE ESPACIO EN DISCO
        $this->showDiskUsageBreakdown();

        // ✅ CONEXIONES DE BASE DE DATOS
        $this->showDatabaseInfo();

        // ✅ CONFIGURACIÓN DE HORIZON
        $this->showHorizonConfig();
    }

    private function showActiveBatches(): void
    {
        $this->line("\n🔄 BATCHES ACTIVOS:");

        // ✅ BATCHES DE IMÁGENES ACTIVOS
        $activeImageBatches = ImageBatch::whereIn('status', ['processing', 'pending'])
            ->with('project:id,name')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        if ($activeImageBatches->isNotEmpty()) {
            $this->table(
                ['ID', 'Proyecto', 'Tipo', 'Progreso', 'Tiempo Activo', 'Estado'],
                $activeImageBatches->map(function($batch) {
                    $progress = $batch->total > 0 ? round(($batch->processed / $batch->total) * 100, 1) : 0;
                    $timeActive = $batch->created_at->diffForHumans();

                    return [
                        $batch->id,
                        $batch->project->name ?? 'N/A',
                        $batch->type ?? 'image-processing',
                        "{$batch->processed}/{$batch->total} ({$progress}%)",
                        $timeActive,
                        $batch->status
                    ];
                })->toArray()
            );
        } else {
            $this->line("   ℹ️ No hay batches de imágenes activos");
        }

        // ✅ BATCHES DE ANÁLISIS ACTIVOS
        $activeAnalysisBatches = AnalysisBatch::where('status', 'processing')
            ->with('project:id,name')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        if ($activeAnalysisBatches->isNotEmpty()) {
            $this->line("\n🤖 ANÁLISIS IA ACTIVOS:");
            $this->table(
                ['ID', 'Proyecto', 'Progreso', 'Tiempo Activo', 'Errores'],
                $activeAnalysisBatches->map(function($batch) {
                    $progress = $batch->total_images > 0 ? round(($batch->processed_images / $batch->total_images) * 100, 1) : 0;
                    $timeActive = $batch->created_at->diffForHumans();

                    return [
                        $batch->id,
                        $batch->project->name ?? 'N/A',
                        "{$batch->processed_images}/{$batch->total_images} ({$progress}%)",
                        $timeActive,
                        $batch->errors ?? 0
                    ];
                })->toArray()
            );
        } else {
            $this->line("   ℹ️ No hay batches de análisis IA activos");
        }
    }

    private function showDiskUsageBreakdown(): void
    {
        $this->line("\n💽 ANÁLISIS DE ESPACIO EN DISCO:");

        $storagePath = storage_path('app');
        $directories = [
            'downloads' => 'Archivos de descarga',
            'reports' => 'Reportes PDF',
            'temp_zips' => 'ZIPs temporales',
            'tmp' => 'Archivos temporales',
            'uploads' => 'Subidas de usuario'
        ];

        $diskData = [];
        foreach ($directories as $dir => $description) {
            $fullPath = "{$storagePath}/{$dir}";
            if (is_dir($fullPath)) {
                $sizeMB = $this->getDirectorySize($fullPath) / 1024 / 1024;
                $fileCount = $this->getFileCount($fullPath);

                $diskData[] = [
                    $dir,
                    $description,
                    round($sizeMB, 1) . ' MB',
                    $fileCount,
                    $this->getSizeStatusEmoji($sizeMB)
                ];
            }
        }

        // ✅ ORDENAR POR TAMAÑO
        usort($diskData, function($a, $b) {
            return (float)$b[2] <=> (float)$a[2];
        });

        $this->table(
            ['Directorio', 'Descripción', 'Tamaño', 'Archivos', 'Estado'],
            $diskData
        );
    }

    private function showDatabaseInfo(): void
    {
        $this->line("\n🗄️ INFORMACIÓN DE BASE DE DATOS:");

        try {
            // ✅ CONEXIONES ACTIVAS
            $connections = DB::select("SHOW PROCESSLIST");
            $activeConnections = count($connections);

            // ✅ TAMAÑO DE TABLAS PRINCIPALES
            $tables = [
                'images' => 'Imágenes',
                'processed_images' => 'Imágenes procesadas',
                'folders' => 'Carpetas',
                'projects' => 'Proyectos',
                'image_batches' => 'Batches de imágenes',
                'analysis_batches' => 'Batches de análisis'
            ];

            $tableData = [];
            foreach ($tables as $table => $description) {
                try {
                    $count = DB::table($table)->count();
                    $tableData[] = [$table, $description, number_format($count)];
                } catch (\Exception $e) {
                    $tableData[] = [$table, $description, 'Error'];
                }
            }

            $this->table(
                ['Tabla', 'Descripción', 'Registros'],
                $tableData
            );

            $this->line("   📊 Conexiones activas: {$activeConnections}");

        } catch (\Exception $e) {
            $this->error("   ❌ Error obteniendo información de BD: " . $e->getMessage());
        }
    }

    private function showHorizonConfig(): void
    {
        $this->line("\n⚡ CONFIGURACIÓN DE HORIZON:");

        $config = config('horizon.environments.production', []);

        if (empty($config)) {
            $this->warn("   ⚠️ No se encontró configuración de Horizon para producción");
            return;
        }

        $supervisors = [];
        foreach ($config as $supervisorName => $supervisorConfig) {
            $queue = is_array($supervisorConfig['queue']) ? implode(', ', $supervisorConfig['queue']) : $supervisorConfig['queue'];
            $processes = isset($supervisorConfig['processes'])
                ? $supervisorConfig['processes']
                : ($supervisorConfig['minProcesses'] . '-' . $supervisorConfig['maxProcesses']);

            $supervisors[] = [
                $supervisorName,
                $queue,
                $processes,
                $supervisorConfig['memory'] . 'MB',
                $supervisorConfig['timeout'] . 's'
            ];
        }

        $this->table(
            ['Supervisor', 'Colas', 'Procesos', 'Memoria', 'Timeout'],
            $supervisors
        );
    }

    private function showAlertsAndRecommendations(): void
    {
        $this->info("\n🚨 ALERTAS Y RECOMENDACIONES");
        $this->line("─────────────────────────");

        $alerts = [];
        $recommendations = [];

        // ✅ VERIFICAR RECURSOS
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $memoryPercent = ($memoryUsage / $memoryLimit) * 100;

        if ($memoryPercent > 80) {
            $alerts[] = "🚨 Uso de memoria alto: " . round($memoryPercent, 1) . "%";
            $recommendations[] = "💡 Considerar reiniciar workers de Horizon";
        }

        // ✅ VERIFICAR DISCO
        $freeBytes = disk_free_space(storage_path('app'));
        $totalBytes = disk_total_space(storage_path('app'));
        $diskPercent = (($totalBytes - $freeBytes) / $totalBytes) * 100;

        if ($diskPercent > 85) {
            $alerts[] = "🚨 Uso de disco alto: " . round($diskPercent, 1) . "%";
            $recommendations[] = "💡 Ejecutar limpieza de archivos temporales: php artisan queue:cleanup";
        }

        // ✅ VERIFICAR BATCHES COLGADOS
        $stuckBatches = ImageBatch::where('status', 'processing')
            ->where('updated_at', '<', now()->subHours(2))
            ->count();

        if ($stuckBatches > 0) {
            $alerts[] = "⚠️ {$stuckBatches} batches posiblemente colgados";
            $recommendations[] = "💡 Revisar batches con: php artisan batches:cleanup";
        }

        // ✅ VERIFICAR CARGA DEL SERVIDOR
        $load = $this->getServerLoad();
        if (($load['1min'] ?? 0) > 6) {
            $alerts[] = "⚠️ Carga del servidor alta: " . ($load['1min'] ?? 'N/A');
            $recommendations[] = "💡 Considerar reducir workers concurrentes";
        }

        // ✅ MOSTRAR ALERTAS
        if (!empty($alerts)) {
            $this->error("ALERTAS DETECTADAS:");
            foreach ($alerts as $alert) {
                $this->line("   " . $alert);
            }
        } else {
            $this->info("✅ No se detectaron alertas críticas");
        }

        // ✅ MOSTRAR RECOMENDACIONES
        if (!empty($recommendations)) {
            $this->warn("\nRECOMENDACIONES:");
            foreach ($recommendations as $recommendation) {
                $this->line("   " . $recommendation);
            }
        }

        // ✅ RECOMENDACIONES GENERALES PARA SERVIDOR POTENTE
        $this->info("\n💡 OPTIMIZACIONES PARA SERVIDOR 8vCPU/32GB:");
        $this->line("   🔧 Horizon configurado para máximo rendimiento");
        $this->line("   ⚡ Colas optimizadas para procesamiento paralelo");
        $this->line("   💾 Límites de memoria aumentados para jobs complejos");
        $this->line("   🤖 Azure API rate limiting inteligente");
    }

    private function exportMetrics(int $hours): void
    {
        $this->info("\n📤 EXPORTANDO MÉTRICAS...");

        try {
            $stats = PerformanceMonitor::getPerformanceStats($hours);
            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "system_metrics_{$timestamp}.json";
            $path = storage_path("logs/{$filename}");

            $exportData = [
                'timestamp' => now()->toISOString(),
                'server_specs' => '8vCPU/32GB',
                'export_period_hours' => $hours,
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'memory_limit' => ini_get('memory_limit'),
                    'environment' => app()->environment()
                ],
                'performance_stats' => $stats,
                'current_resources' => [
                    'memory_usage' => memory_get_usage(true),
                    'memory_peak' => memory_get_peak_usage(true),
                    'server_load' => $this->getServerLoad()
                ]
            ];

            file_put_contents($path, json_encode($exportData, JSON_PRETTY_PRINT));

            $this->info("✅ Métricas exportadas a: {$filename}");

        } catch (\Exception $e) {
            $this->error("❌ Error exportando métricas: " . $e->getMessage());
        }
    }

    // ✅ MÉTODOS AUXILIARES

    private function getStatusEmoji(float $percentage, float $warning = 80, float $critical = 90): string
    {
        return match(true) {
            $percentage < $warning => '✅',
            $percentage < $critical => '⚠️',
            default => '🚨'
        };
    }

    private function getLoadStatusEmoji(float $load): string
    {
        return match(true) {
            $load < 4 => '✅',  // Menos del 50% en 8 vCPUs
            $load < 6 => '⚠️',  // 50-75%
            default => '🚨'     // Más del 75%
        };
    }

    private function getQueueStatusEmoji(int $total): string
    {
        return match(true) {
            $total === 0 => '✅',
            $total < 50 => '🟡',
            $total < 200 => '⚠️',
            default => '🚨'
        };
    }

    private function getSizeStatusEmoji(float $sizeMB): string
    {
        return match(true) {
            $sizeMB < 100 => '✅',
            $sizeMB < 500 => '🟡',
            $sizeMB < 1000 => '⚠️',
            default => '🚨'
        };
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    private function parseMemoryLimit(string $memoryLimit): int
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $number = (int) $memoryLimit;

        switch ($last) {
            case 'g': $number *= 1024;
            case 'm': $number *= 1024;
            case 'k': $number *= 1024;
        }

        return $number;
    }

    private function getServerLoad(): array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }
        return ['1min' => 0, '5min' => 0, '15min' => 0];
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // Ignorar errores de permisos
        }

        return $size;
    }

    private function getFileCount(string $path): int
    {
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        } catch (\Exception $e) {
            // Ignorar errores de permisos
        }

        return $count;
    }
}
