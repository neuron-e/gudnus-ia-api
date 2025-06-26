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
        // Para ImageBatch
        Schema::table('image_batches', function (Blueprint $table) {
            // Si es ENUM, necesitas recrear la columna
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'completed_with_errors',
                'failed',
                'cancelled'  // ✅ Agregar cancelled
            ])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'completed_with_errors',
                'failed'
            ])->change();
        });
    }
};
