<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WasabiInvestigatorCommand extends Command
{
    protected $signature = 'wasabi:investigate
                           {--clean : Limpiar archivos misteriosos antiguos}
                           {--days=7 : Días de antigüedad para considerar archivo limpiable}
                           {--dry-run : Solo mostrar qué se haría sin ejecutar}
                           {--pattern=gud-* : Patrón de archivos a buscar}';

    protected $description = 'Investigar y limpiar archivos misteriosos en Wasabi';

    public function handle()
    {
        $this->info('🔍 INVESTIGADOR DE ARCHIVOS WASABI');
        $this->info('====================================');

        $clean = $this->option('clean');
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $pattern = $this->option('pattern');

        try {
            $wasabi = Storage::disk('wasabi');

            // ✅ PASO 1: Inventario completo
            $this->info("📊 Realizando inventario completo de Wasabi...");
            $inventory = $this->performInventory($wasabi);
            $this->displayInventory($inventory);

            // ✅ PASO 2: Investigar archivos misteriosos
            $this->info("\n🔍 Investigando archivos misteriosos...");
            $mysteryFiles = $this->findMysteryFiles($wasabi, $pattern);
            $this->displayMysteryFiles($mysteryFiles);

            // ✅ PASO 3: Análisis de patrones
            $this->info("\n📈 Analizando patrones...");
            $this->analyzePatterns($mysteryFiles);

            // ✅ PASO 4: Limpieza si se solicita
            if ($clean) {
                $this->info("\n🧹 Iniciando limpieza...");
                $this->performCleanup($wasabi, $mysteryFiles, $days, $dryRun);
            }

            // ✅ PASO 5: Recomendaciones
            $this->info("\n💡 Generando recomendaciones...");
            $this->generateRecommendations($mysteryFiles);

        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            Log::error("WasabiInvestigator error: " . $e->getMessage());
        }
    }

    /**
     * ✅ INVENTARIO COMPLETO DE WASABI
     */
    private function performInventory($wasabi): array
    {
        $allFiles = $wasabi->allFiles();
        $inventory = [
            'total_files' => count($allFiles),
            'total_size' => 0,
            'by_type' => [],
            'by_directory' => [],
            'oldest_file' => null,
            'newest_file' => null,
            'largest_file' => null
        ];

        $oldestTime = PHP_INT_MAX;
        $newestTime = 0;
        $largestSize = 0;

        foreach ($allFiles as $file) {
            try {
                $size = $wasabi->size($file);
                $lastModified = $wasabi->lastModified($file);

                $inventory['total_size'] += $size;

                // Por tipo (extensión)
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION)) ?: 'sin_extension';
                $inventory['by_type'][$extension] = ($inventory['by_type'][$extension] ?? 0) + 1;

                // Por directorio
                $directory = dirname($file);
                if (!isset($inventory['by_directory'][$directory])) {
                    $inventory['by_directory'][$directory] = ['count' => 0, 'size' => 0];
                }
                $inventory['by_directory'][$directory]['count']++;
                $inventory['by_directory'][$directory]['size'] += $size;

                // Archivos extremos
                if ($lastModified < $oldestTime) {
                    $oldestTime = $lastModified;
                    $inventory['oldest_file'] = ['file' => $file, 'date' => date('Y-m-d H:i:s', $lastModified)];
                }

                if ($lastModified > $newestTime) {
                    $newestTime = $lastModified;
                    $inventory['newest_file'] = ['file' => $file, 'date' => date('Y-m-d H:i:s', $lastModified)];
                }

                if ($size > $largestSize) {
                    $largestSize = $size;
                    $inventory['largest_file'] = ['file' => $file, 'size_mb' => round($size / 1024 / 1024, 2)];
                }

            } catch (\Exception $e) {
                $this->warn("⚠️ Error procesando archivo {$file}: " . $e->getMessage());
            }
        }

        return $inventory;
    }

    /**
     * ✅ MOSTRAR INVENTARIO
     */
    private function displayInventory($inventory): void
    {
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total de archivos', number_format($inventory['total_files'])],
                ['Tamaño total', $this->formatBytes($inventory['total_size'])],
                ['Archivo más antiguo', $inventory['oldest_file']['file'] ?? 'N/A'],
                ['Fecha más antigua', $inventory['oldest_file']['date'] ?? 'N/A'],
                ['Archivo más reciente', $inventory['newest_file']['file'] ?? 'N/A'],
                ['Fecha más reciente', $inventory['newest_file']['date'] ?? 'N/A'],
                ['Archivo más grande', $inventory['largest_file']['file'] ?? 'N/A'],
                ['Tamaño más grande', ($inventory['largest_file']['size_mb'] ?? 0) . ' MB'],
            ]
        );

        // Top 10 extensiones
        if (!empty($inventory['by_type'])) {
            arsort($inventory['by_type']);
            $this->info("\n📁 Top 10 tipos de archivo:");
            $topTypes = array_slice($inventory['by_type'], 0, 10, true);
            foreach ($topTypes as $type => $count) {
                $this->line("  {$type}: " . number_format($count) . " archivos");
            }
        }

        // Top 10 directorios
        if (!empty($inventory['by_directory'])) {
            uasort($inventory['by_directory'], function($a, $b) {
                return $b['size'] <=> $a['size'];
            });

            $this->info("\n🗂️ Top 10 directorios por tamaño:");
            $topDirs = array_slice($inventory['by_directory'], 0, 10, true);
            foreach ($topDirs as $dir => $info) {
                $this->line("  {$dir}: " . number_format($info['count']) . " archivos, " . $this->formatBytes($info['size']));
            }
        }
    }

    /**
     * ✅ ENCONTRAR ARCHIVOS MISTERIOSOS
     */
    private function findMysteryFiles($wasabi, string $pattern): array
    {
        $allFiles = $wasabi->allFiles();
        $mysteryFiles = [];

        // Convertir patrón a regex
        $regexPattern = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '/';

        foreach ($allFiles as $file) {
            $basename = basename($file);

            // Buscar archivos que coincidan con el patrón
            if (preg_match($regexPattern, $basename)) {
                try {
                    $size = $wasabi->size($file);
                    $lastModified = $wasabi->lastModified($file);
                    $ageDays = (time() - $lastModified) / 86400;

                    $mysteryFiles[] = [
                        'file' => $file,
                        'basename' => $basename,
                        'size' => $size,
                        'size_mb' => round($size / 1024 / 1024, 2),
                        'last_modified' => $lastModified,
                        'last_modified_human' => date('Y-m-d H:i:s', $lastModified),
                        'age_days' => round($ageDays, 1),
                        'directory' => dirname($file)
                    ];
                } catch (\Exception $e) {
                    $this->warn("⚠️ Error procesando archivo misterioso {$file}: " . $e->getMessage());
                }
            }
        }

        // Ordenar por fecha (más recientes primero)
        usort($mysteryFiles, function($a, $b) {
            return $b['last_modified'] <=> $a['last_modified'];
        });

        return $mysteryFiles;
    }

    /**
     * ✅ MOSTRAR ARCHIVOS MISTERIOSOS
     */
    private function displayMysteryFiles($mysteryFiles): void
    {
        if (empty($mysteryFiles)) {
            $this->info("✅ No se encontraron archivos misteriosos");
            return;
        }

        $totalSize = array_sum(array_column($mysteryFiles, 'size'));
        $totalCount = count($mysteryFiles);

        $this->warn("🚨 Encontrados {$totalCount} archivos misteriosos ocupando " . $this->formatBytes($totalSize));

        // Mostrar muestra de archivos
        $this->info("\n📋 Muestra de archivos misteriosos (últimos 15):");
        $headers = ['Archivo', 'Tamaño', 'Fecha', 'Antigüedad', 'Directorio'];
        $rows = [];

        foreach (array_slice($mysteryFiles, 0, 15) as $file) {
            $rows[] = [
                substr($file['basename'], 0, 40) . (strlen($file['basename']) > 40 ? '...' : ''),
                $file['size_mb'] . ' MB',
                $file['last_modified_human'],
                $file['age_days'] . ' días',
                $file['directory']
            ];
        }

        $this->table($headers, $rows);

        if (count($mysteryFiles) > 15) {
            $this->info("... y " . (count($mysteryFiles) - 15) . " archivos más");
        }
    }

    /**
     * ✅ ANALIZAR PATRONES
     */
    private function analyzePatterns($mysteryFiles): void
    {
        if (empty($mysteryFiles)) {
            return;
        }

        // Análisis por edad
        $ageGroups = [
            '< 1 día' => 0,
            '1-7 días' => 0,
            '7-30 días' => 0,
            '> 30 días' => 0
        ];

        foreach ($mysteryFiles as $file) {
            $age = $file['age_days'];
            if ($age < 1) {
                $ageGroups['< 1 día']++;
            } elseif ($age <= 7) {
                $ageGroups['1-7 días']++;
            } elseif ($age <= 30) {
                $ageGroups['7-30 días']++;
            } else {
                $ageGroups['> 30 días']++;
            }
        }

        $this->table(
            ['Rango de Edad', 'Cantidad de Archivos'],
            array_map(function($group, $count) {
                return [$group, $count];
            }, array_keys($ageGroups), $ageGroups)
        );

        // Análisis por tamaño
        $sizeGroups = [
            '< 1 MB' => 0,
            '1-10 MB' => 0,
            '10-100 MB' => 0,
            '100-1000 MB' => 0,
            '> 1000 MB' => 0
        ];

        foreach ($mysteryFiles as $file) {
            $sizeMB = $file['size_mb'];
            if ($sizeMB < 1) {
                $sizeGroups['< 1 MB']++;
            } elseif ($sizeMB <= 10) {
                $sizeGroups['1-10 MB']++;
            } elseif ($sizeMB <= 100) {
                $sizeGroups['10-100 MB']++;
            } elseif ($sizeMB <= 1000) {
                $sizeGroups['100-1000 MB']++;
            } else {
                $sizeGroups['> 1000 MB']++;
            }
        }

        $this->info("\n📊 Distribución por tamaño:");
        $this->table(
            ['Rango de Tamaño', 'Cantidad de Archivos'],
            array_map(function($group, $count) {
                return [$group, $count];
            }, array_keys($sizeGroups), $sizeGroups)
        );

        // Detectar patrones en nombres
        $this->analyzeNamePatterns($mysteryFiles);
    }

    /**
     * ✅ ANALIZAR PATRONES EN NOMBRES
     */
    private function analyzeNamePatterns($mysteryFiles): void
    {
        $this->info("\n🔍 Analizando patrones en nombres de archivo...");

        $patterns = [];
        foreach ($mysteryFiles as $file) {
            $name = $file['basename'];

            // Extraer patrón general
            if (preg_match('/^gud-(\d{4})-(\d{2})-(\d{2})-(.+)$/', $name, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $suffix = $matches[4];

                $monthKey = "{$year}-{$month}";
                $patterns[$monthKey] = ($patterns[$monthKey] ?? 0) + 1;
            }
        }

        if (!empty($patterns)) {
            ksort($patterns);
            $this->info("\n📅 Archivos por mes:");
            foreach ($patterns as $month => $count) {
                $this->line("  {$month}: {$count} archivos");
            }
        }

        // Buscar archivos con patrones sospechosos
        $suspicious = array_filter($mysteryFiles, function($file) {
            return $file['age_days'] > 7 && $file['size_mb'] > 100;
        });

        if (!empty($suspicious)) {
            $this->warn("\n🚨 Archivos sospechosos (>7 días y >100MB): " . count($suspicious));
        }
    }

    /**
     * ✅ REALIZAR LIMPIEZA
     */
    private function performCleanup($wasabi, $mysteryFiles, int $days, bool $dryRun): void
    {
        $filesToClean = array_filter($mysteryFiles, function($file) use ($days) {
            return $file['age_days'] > $days;
        });

        if (empty($filesToClean)) {
            $this->info("✅ No hay archivos que cumplan los criterios de limpieza (>{$days} días)");
            return;
        }

        $totalSize = array_sum(array_column($filesToClean, 'size'));
        $totalCount = count($filesToClean);

        if ($dryRun) {
            $this->warn("🔍 MODO DRY-RUN: Se eliminarían {$totalCount} archivos liberando " . $this->formatBytes($totalSize));

            $this->info("\nArchivos que se eliminarían:");
            foreach (array_slice($filesToClean, 0, 10) as $file) {
                $this->line("  - {$file['basename']} ({$file['size_mb']} MB, {$file['age_days']} días)");
            }

            if (count($filesToClean) > 10) {
                $this->line("  ... y " . (count($filesToClean) - 10) . " archivos más");
            }

            return;
        }

        if (!$this->confirm("¿Eliminar {$totalCount} archivos liberando " . $this->formatBytes($totalSize) . "?")) {
            $this->info("Operación cancelada por el usuario");
            return;
        }

        $progress = $this->output->createProgressBar($totalCount);
        $progress->start();

        $cleaned = 0;
        $spaceSaved = 0;
        $errors = 0;

        foreach ($filesToClean as $file) {
            try {
                $wasabi->delete($file['file']);
                $cleaned++;
                $spaceSaved += $file['size'];

                Log::info("Archivo misterioso eliminado: " . $file['file']);
            } catch (\Exception $e) {
                $errors++;
                Log::error("Error eliminando archivo misterioso {$file['file']}: " . $e->getMessage());
            }

            $progress->advance();
        }

        $progress->finish();

        $this->info("\n✅ Limpieza completada:");
        $this->line("  Archivos eliminados: {$cleaned}");
        $this->line("  Errores: {$errors}");
        $this->line("  Espacio liberado: " . $this->formatBytes($spaceSaved));
    }

    /**
     * ✅ GENERAR RECOMENDACIONES
     */
    private function generateRecommendations($mysteryFiles): void
    {
        $this->info("💡 RECOMENDACIONES:");

        if (empty($mysteryFiles)) {
            $this->line("  ✅ No hay archivos misteriosos - sistema limpio");
            return;
        }

        $oldFiles = array_filter($mysteryFiles, fn($f) => $f['age_days'] > 7);
        $largeFiles = array_filter($mysteryFiles, fn($f) => $f['size_mb'] > 100);
        $veryOldFiles = array_filter($mysteryFiles, fn($f) => $f['age_days'] > 30);

        if (!empty($oldFiles)) {
            $this->line("  🧹 Considera limpiar " . count($oldFiles) . " archivos de más de 7 días");
            $this->line("     Comando: php artisan wasabi:investigate --clean --days=7");
        }

        if (!empty($largeFiles)) {
            $this->line("  📦 Hay " . count($largeFiles) . " archivos grandes (>100MB) que pueden ser uploads fallidos");
        }

        if (!empty($veryOldFiles)) {
            $this->line("  🗑️ " . count($veryOldFiles) . " archivos muy antiguos (>30 días) se pueden eliminar de forma segura");
            $this->line("     Comando: php artisan wasabi:investigate --clean --days=30");
        }

        // Recomendaciones de prevención
        $this->line("  🔧 PREVENCIÓN:");
        $this->line("     • Configurar limpieza automática con cron job");
        $this->line("     • Implementar timeout en uploads para evitar archivos huérfanos");
        $this->line("     • Revisar logs de uploads fallidos");

        $totalSize = array_sum(array_column($mysteryFiles, 'size'));
        if ($totalSize > 1024 * 1024 * 1024) { // > 1GB
            $this->warn("  ⚠️ Los archivos misteriosos ocupan más de 1GB - limpieza urgente recomendada");
        }
    }

    /**
     * ✅ FORMATEAR BYTES
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
}
