<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Folder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateImageProjectIds extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'migrate:image-project-ids {--dry-run : Solo mostrar qué se haría sin ejecutar}';

    /**
     * The console command description.
     */
    protected $description = 'Migrar project_id a todas las imágenes existentes basándose en su folder';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info("🔄 Migrando project_id a imágenes existentes...");

        if ($isDryRun) {
            $this->warn("🔍 MODO DRY-RUN - No se harán cambios reales");
        }

        try {
            // ✅ Obtener imágenes sin project_id
            $imagesWithoutProjectId = Image::whereNull('project_id')
                ->with('folder')
                ->get();

            if ($imagesWithoutProjectId->isEmpty()) {
                $this->info("✅ Todas las imágenes ya tienen project_id asignado");
                return;
            }

            $this->info("📊 Encontradas {$imagesWithoutProjectId->count()} imágenes sin project_id");

            $updated = 0;
            $errors = 0;

            // ✅ Procesar en chunks para mejor performance
            $imagesWithoutProjectId->chunk(100)->each(function($images) use (&$updated, &$errors, $isDryRun) {
                foreach ($images as $image) {
                    try {
                        if (!$image->folder) {
                            $this->error("❌ Imagen {$image->id} no tiene folder asociado");
                            $errors++;
                            continue;
                        }

                        if (!$image->folder->project_id) {
                            $this->error("❌ Folder {$image->folder->id} no tiene project_id");
                            $errors++;
                            continue;
                        }

                        if ($isDryRun) {
                            $this->line("🔍 Imagen {$image->id} → project_id: {$image->folder->project_id}");
                        } else {
                            $image->update(['project_id' => $image->folder->project_id]);
                            $this->line("✅ Imagen {$image->id} → project_id: {$image->folder->project_id}");
                        }

                        $updated++;

                    } catch (\Throwable $e) {
                        $this->error("❌ Error con imagen {$image->id}: " . $e->getMessage());
                        $errors++;
                    }
                }
            });

            // ✅ Resumen
            $this->newLine();
            $this->info("📊 RESUMEN:");
            $this->line("  ✅ Imágenes procesadas: {$updated}");
            $this->line("  ❌ Errores: {$errors}");

            if (!$isDryRun && $updated > 0) {
                $this->info("🎉 Migración completada exitosamente");

                // ✅ Verificar consistencia
                $this->verifyConsistency();
            }

        } catch (\Throwable $e) {
            $this->error("❌ Error en migración: " . $e->getMessage());
            $this->error($e->getTraceAsString());
        }
    }

    /**
     * ✅ Verificar consistencia después de la migración
     */
    private function verifyConsistency(): void
    {
        $this->info("🔍 Verificando consistencia...");

        // Verificar que no hay imágenes sin project_id
        $imagesWithoutProject = Image::whereNull('project_id')->count();

        if ($imagesWithoutProject === 0) {
            $this->info("  ✅ Todas las imágenes tienen project_id");
        } else {
            $this->warn("  ⚠️ Aún hay {$imagesWithoutProject} imágenes sin project_id");
        }

        // Verificar consistencia folder.project_id = image.project_id
        $inconsistentImages = DB::table('images')
            ->join('folders', 'images.folder_id', '=', 'folders.id')
            ->whereColumn('images.project_id', '!=', 'folders.project_id')
            ->count();

        if ($inconsistentImages === 0) {
            $this->info("  ✅ Consistencia folder↔image verificada");
        } else {
            $this->error("  ❌ {$inconsistentImages} imágenes con project_id inconsistente");
        }

        // Estadísticas por proyecto
        $projectStats = DB::table('images')
            ->select('project_id', DB::raw('COUNT(*) as image_count'))
            ->whereNotNull('project_id')
            ->groupBy('project_id')
            ->orderBy('project_id')
            ->get();

        $this->info("📊 Imágenes por proyecto:");
        foreach ($projectStats as $stat) {
            $this->line("  - Proyecto {$stat->project_id}: {$stat->image_count} imágenes");
        }
    }
}
