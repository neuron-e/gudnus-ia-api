<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PerformanceMonitor
{
    private static array $timers = [];
    private static array $memorySnapshots = [];

    /**
     * ✅ INICIAR MONITOREO DE PERFORMANCE
     */
    public static function startJob(string $jobName, array $context = []): void
    {
        $key = self::generateKey($jobName);

        self::$timers[$key] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'job_name' => $jobName,
            'context' => $context
        ];

        Log::channel('performance')->info("🚀 [START] {$jobName}", array_merge($context, [
            'memory_start' => self::formatBytes(memory_get_usage(true)),
            'memory_limit' => ini_get('memory_limit'),
            'server_load' => self::getServerLoad()
        ]));
    }

    /**
     * ✅ TOMAR SNAPSHOT INTERMEDIO
     */
    public static function checkpoint(string $jobName, string $stage, array $context = []): void
    {
        $key = self::generateKey($jobName);

        if (!isset(self::$timers[$key])) {
            Log::channel('performance')->warning("⚠️ Checkpoint sin inicio: {$jobName} - {$stage}");
            return;
        }

        $timer = self::$timers[$key];
        $elapsedTime = microtime(true) - $timer['start_time'];
        $currentMemory = memory_get_usage(true);
        $memoryDiff = $currentMemory - $timer['start_memory'];

        Log::channel('performance')->info("📊 [CHECKPOINT] {$jobName} - {$stage}", array_merge($context, [
            'elapsed_time' => round($elapsedTime, 2) . 's',
            'memory_current' => self::formatBytes($currentMemory),
            'memory_diff' => self::formatBytes($memoryDiff),
            'memory_peak' => self::formatBytes(memory_get_peak_usage(true))
        ]));
    }

    /**
     * ✅ FINALIZAR MONITOREO Y GENERAR REPORTE
     */
    public static function endJob(string $jobName, bool $success = true, array $context = []): void
    {
        $key = self::generateKey($jobName);

        if (!isset(self::$timers[$key])) {
            Log::channel('performance')->warning("⚠️ Fin sin inicio: {$jobName}");
            return;
        }

        $timer = self::$timers[$key];
        $totalTime = microtime(true) - $timer['start_time'];
        $finalMemory = memory_get_usage(true);
        $memoryUsed = $finalMemory - $timer['start_memory'];
        $peakMemory = memory_get_peak_usage(true);

        $status = $success ? 'SUCCESS' : 'FAILED';
        $emoji = $success ? '✅' : '❌';

        // ✅ LOG DETALLADO DE PERFORMANCE
        Log::channel('performance')->info("{$emoji} [END] {$jobName} - {$status}", array_merge($context, [
            'total_time' => round($totalTime, 2) . 's',
            'memory_used' => self::formatBytes($memoryUsed),
            'memory_peak' => self::formatBytes($peakMemory),
            'memory_final' => self::formatBytes($finalMemory),
            'server_load_end' => self::getServerLoad(),
            'performance_rating' => self::getPerformanceRating($totalTime, $peakMemory)
        ]));

        // ✅ ALERTAS DE PERFORMANCE
        self::checkPerformanceAlerts($jobName, $totalTime, $peakMemory);

        // ✅ GUARDAR MÉTRICAS PARA ANÁLISIS
        self::saveMetrics($jobName, $totalTime, $peakMemory, $success, $context);

        // ✅ LIMPIAR TIMER
        unset(self::$timers[$key]);
    }

    /**
     * ✅ MONITOREO ESPECÍFICO PARA AZURE API
     */
    public static function logAzureRequest(int $imageId, float $responseTime, int $statusCode, int $attempts = 1): void
    {
        $status = $statusCode >= 200 && $statusCode < 300 ? 'SUCCESS' : 'FAILED';
        $emoji = $status === 'SUCCESS' ? '🤖' : '❌';

        Log::channel('analysis')->info("{$emoji} [AZURE] Imagen {$imageId} - {$status}", [
            'response_time' => round($responseTime, 3) . 's',
            'status_code' => $statusCode,
            'attempts' => $attempts,
            'rate_limit_status' => $statusCode === 429 ? 'HIT' : 'OK',
            'performance_tier' => self::getAzurePerformanceTier($responseTime)
        ]);

        // ✅ MÉTRICAS ESPECÍFICAS DE AZURE
        self::trackAzureMetrics($responseTime, $statusCode, $attempts);
    }

    /**
     * ✅ MONITOREO DE BATCH PROGRESS
     */
    public static function logBatchProgress(string $batchType, int $batchId, int $processed, int $total, array $context = []): void
    {
        $progress = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
        $remaining = $total - $processed;

        Log::channel('batches')->info("📊 [BATCH] {$batchType} #{$batchId} - {$progress}%", array_merge($context, [
            'processed' => $processed,
            'total' => $total,
            'remaining' => $remaining,
            'progress_percent' => $progress,
            'estimated_completion' => self::estimateCompletion($processed, $total, $context['start_time'] ?? time())
        ]));
    }

    /**
     * ✅ MONITOREO DE RECURSOS DEL SISTEMA
     */
    public static function logSystemResources(string $context = 'general'): void
    {
        $load = self::getServerLoad();
        $memory = self::getMemoryStats();
        $disk = self::getDiskStats();

        Log::channel('performance')->info("🖥️ [SYSTEM] Recursos - {$context}", [
            'server_load' => $load,
            'memory_usage_percent' => $memory['usage_percent'],
            'memory_available' => $memory['available'],
            'disk_free_gb' => $disk['free_gb'],
            'disk_usage_percent' => $disk['usage_percent'],
            'active_processes' => self::getActiveProcessCount()
        ]);

        // ✅ ALERTAS DE RECURSOS
        self::checkResourceAlerts($memory, $disk, $load);
    }

    // ✅ MÉTODOS AUXILIARES PRIVADOS

    private static function generateKey(string $jobName): string
    {
        return md5($jobName . microtime(true) . random_int(1000, 9999));
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }

    private static function getServerLoad(): array
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

    private static function getMemoryStats(): array
    {
        $memoryLimit = self::parseMemoryLimit(ini_get('memory_limit'));
        $currentUsage = memory_get_usage(true);
        $peakUsage = memory_get_peak_usage(true);

        return [
            'current' => self::formatBytes($currentUsage),
            'peak' => self::formatBytes($peakUsage),
            'limit' => self::formatBytes($memoryLimit),
            'usage_percent' => round(($currentUsage / $memoryLimit) * 100, 1),
            'available' => self::formatBytes($memoryLimit - $currentUsage)
        ];
    }

    private static function getDiskStats(): array
    {
        $path = storage_path('app');
        $freeBytes = disk_free_space($path);
        $totalBytes = disk_total_space($path);

        if (!$freeBytes || !$totalBytes) {
            return ['free_gb' => 0, 'total_gb' => 0, 'usage_percent' => 100];
        }

        $freeGB = round($freeBytes / 1024 / 1024 / 1024, 2);
        $totalGB = round($totalBytes / 1024 / 1024 / 1024, 2);
        $usagePercent = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);

        return [
            'free_gb' => $freeGB,
            'total_gb' => $totalGB,
            'usage_percent' => $usagePercent
        ];
    }

    private static function getActiveProcessCount(): int
    {
        if (function_exists('shell_exec') && PHP_OS_FAMILY === 'Linux') {
            $result = shell_exec('ps aux | wc -l');
            return (int) trim($result);
        }
        return 0;
    }

    private static function parseMemoryLimit(string $memoryLimit): int
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

    private static function getPerformanceRating(float $time, int $peakMemory): string
    {
        $memoryMB = $peakMemory / 1024 / 1024;

        return match(true) {
            $time < 30 && $memoryMB < 256 => 'EXCELLENT',
            $time < 120 && $memoryMB < 512 => 'GOOD',
            $time < 300 && $memoryMB < 1024 => 'FAIR',
            $time < 600 && $memoryMB < 2048 => 'SLOW',
            default => 'POOR'
        };
    }

    private static function getAzurePerformanceTier(float $responseTime): string
    {
        return match(true) {
            $responseTime < 2 => 'FAST',
            $responseTime < 5 => 'NORMAL',
            $responseTime < 10 => 'SLOW',
            default => 'VERY_SLOW'
        };
    }

    private static function checkPerformanceAlerts(string $jobName, float $totalTime, int $peakMemory): void
    {
        $memoryMB = $peakMemory / 1024 / 1024;

        // ✅ ALERTAS DE TIEMPO
        if ($totalTime > 600) { // 10 minutos
            Log::channel('critical')->warning("⚠️ [SLOW JOB] {$jobName} tardó " . round($totalTime, 1) . "s");
        }

        // ✅ ALERTAS DE MEMORIA
        if ($memoryMB > 1024) { // 1GB
            Log::channel('critical')->warning("⚠️ [HIGH MEMORY] {$jobName} usó " . round($memoryMB, 1) . "MB");
        }
    }

    private static function checkResourceAlerts(array $memory, array $disk, array $load): void
    {
        // ✅ ALERTAS DE MEMORIA
        if ($memory['usage_percent'] > 80) {
            Log::channel('critical')->error("🚨 [MEMORY ALERT] Uso de memoria: {$memory['usage_percent']}%");
        }

        // ✅ ALERTAS DE DISCO
        if ($disk['usage_percent'] > 85) {
            Log::channel('critical')->error("🚨 [DISK ALERT] Uso de disco: {$disk['usage_percent']}%");
        }

        // ✅ ALERTAS DE CARGA
        if ($load['1min'] > 6) { // Para 8 vCPUs
            Log::channel('critical')->warning("⚠️ [LOAD ALERT] Carga del servidor: {$load['1min']}");
        }
    }

    private static function estimateCompletion(int $processed, int $total, int $startTime): string
    {
        if ($processed <= 0) return 'Calculando...';

        $elapsed = time() - $startTime;
        $rate = $processed / max($elapsed, 1);
        $remaining = $total - $processed;
        $eta = $remaining / max($rate, 0.01);

        if ($eta < 60) {
            return round($eta) . ' segundos';
        } elseif ($eta < 3600) {
            return round($eta / 60) . ' minutos';
        } else {
            return round($eta / 3600, 1) . ' horas';
        }
    }

    private static function saveMetrics(string $jobName, float $time, int $memory, bool $success, array $context): void
    {
        try {
            // ✅ GUARDAR MÉTRICAS EN REDIS PARA ANÁLISIS
            $metrics = [
                'job_name' => $jobName,
                'execution_time' => $time,
                'peak_memory' => $memory,
                'success' => $success,
                'timestamp' => time(),
                'context' => $context
            ];

            Redis::lpush('performance_metrics', json_encode($metrics));
            Redis::ltrim('performance_metrics', 0, 999); // Mantener últimas 1000 métricas

        } catch (\Exception $e) {
            Log::channel('performance')->warning("⚠️ Error guardando métricas: " . $e->getMessage());
        }
    }

    private static function trackAzureMetrics(float $responseTime, int $statusCode, int $attempts): void
    {
        try {
            $azureMetric = [
                'response_time' => $responseTime,
                'status_code' => $statusCode,
                'attempts' => $attempts,
                'timestamp' => time(),
                'success' => $statusCode >= 200 && $statusCode < 300
            ];

            Redis::lpush('azure_metrics', json_encode($azureMetric));
            Redis::ltrim('azure_metrics', 0, 499); // Mantener últimas 500 métricas Azure

        } catch (\Exception $e) {
            Log::channel('analysis')->warning("⚠️ Error guardando métricas Azure: " . $e->getMessage());
        }
    }

    /**
     * ✅ OBTENER ESTADÍSTICAS RESUMIDAS
     */
    public static function getPerformanceStats(int $hours = 24): array
    {
        try {
            $metrics = Redis::lrange('performance_metrics', 0, -1);
            $azureMetrics = Redis::lrange('azure_metrics', 0, -1);

            $cutoff = time() - ($hours * 3600);
            $recentMetrics = [];
            $recentAzureMetrics = [];

            foreach ($metrics as $metric) {
                $data = json_decode($metric, true);
                if ($data['timestamp'] >= $cutoff) {
                    $recentMetrics[] = $data;
                }
            }

            foreach ($azureMetrics as $metric) {
                $data = json_decode($metric, true);
                if ($data['timestamp'] >= $cutoff) {
                    $recentAzureMetrics[] = $data;
                }
            }

            return [
                'total_jobs' => count($recentMetrics),
                'successful_jobs' => count(array_filter($recentMetrics, fn($m) => $m['success'])),
                'avg_execution_time' => self::calculateAverage($recentMetrics, 'execution_time'),
                'avg_memory_usage' => self::calculateAverage($recentMetrics, 'peak_memory'),
                'azure_total_requests' => count($recentAzureMetrics),
                'azure_success_rate' => self::calculateSuccessRate($recentAzureMetrics),
                'azure_avg_response_time' => self::calculateAverage($recentAzureMetrics, 'response_time'),
                'period_hours' => $hours
            ];

        } catch (\Exception $e) {
            Log::channel('performance')->error("Error obteniendo estadísticas: " . $e->getMessage());
            return [];
        }
    }

    private static function calculateAverage(array $data, string $field): float
    {
        if (empty($data)) return 0;

        $sum = array_sum(array_column($data, $field));
        return round($sum / count($data), 2);
    }

    private static function calculateSuccessRate(array $data): float
    {
        if (empty($data)) return 0;

        $successful = count(array_filter($data, fn($m) => $m['success']));
        return round(($successful / count($data)) * 100, 1);
    }
}
