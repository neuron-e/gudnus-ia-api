<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANTE: Solo ejecutar DESPUÉS de correr php artisan migrate:image-project-ids
     */
    public function up(): void
    {
        // ✅ Verificar que no hay imágenes sin project_id antes de continuar
        $imagesWithoutProjectId = \DB::table('images')->whereNull('project_id')->count();

        if ($imagesWithoutProjectId > 0) {
            throw new \Exception(
                "ERROR: Hay {$imagesWithoutProjectId} imágenes sin project_id. " .
                "Ejecuta 'php artisan migrate:image-project-ids' primero."
            );
        }

        Schema::table('images', function (Blueprint $table) {
            // ✅ Hacer project_id NOT NULL
            $table->foreignId('project_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // ✅ Volver a hacer nullable para rollback seguro
            $table->foreignId('project_id')->nullable()->change();
        });
    }
};
