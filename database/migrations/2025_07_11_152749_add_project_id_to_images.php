<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            // ✅ Agregar project_id con foreign key
            $table->foreignId('project_id')
                ->after('id')
                ->nullable() // Nullable inicialmente para migración de datos existentes
                ->constrained()
                ->onDelete('cascade');

            // ✅ Índice para mejorar performance de consultas
            $table->index(['project_id', 'folder_id']);
            $table->index(['project_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id', 'folder_id']);
            $table->dropIndex(['project_id', 'status']);
            $table->dropColumn('project_id');
        });
    }
};
