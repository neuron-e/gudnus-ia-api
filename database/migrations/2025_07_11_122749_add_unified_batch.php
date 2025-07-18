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
        Schema::create('unified_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');

            // ✅ Tipos de operación unificados
            $table->enum('type', [
                'image_processing',    // Procesamiento de imágenes individuales
                'zip_processing',      // Subida y extracción de ZIPs
                'analysis',           // Análisis IA masivo
                'download_generation', // Generación de ZIPs descarga
                'report_generation'   // Generación de reportes PDF
            ]);

            // ✅ Estados del batch
            $table->enum('status', [
                'pending',
                'processing',
                'paused',
                'completed',
                'completed_with_errors',
                'failed',
                'cancelled',
                'cancelling'  // Estado intermedio para cancelación limpia
            ])->default('pending');

            // ✅ Configuración específica del batch (JSON)
            $table->json('config')->nullable();
            // Ejemplos:
            // image_processing: {"operation": "crop", "image_ids": [1,2,3]}
            // download_generation: {"type": "analyzed", "include_metadata": true}
            // report_generation: {"include_analyzed": true, "template": "standard"}

            // ✅ Datos de entrada del batch
            $table->json('input_data')->nullable();

            // ✅ Progreso detallado
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->integer('skipped_items')->default(0);

            // ✅ CLAVE: Control de jobs para evitar inconsistencias
            $table->integer('active_jobs')->default(0);
            $table->json('job_ids')->nullable(); // Track de jobs activos en Redis

            // ✅ Timing y performance
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->integer('estimated_duration_seconds')->nullable();

            // ✅ Storage y resultados
            $table->string('storage_path')->nullable(); // Path base en Wasabi
            $table->json('generated_files')->nullable(); // Arrays de paths generados
            $table->string('download_url')->nullable();  // URL temporal de descarga
            $table->timestamp('expires_at')->nullable(); // Para downloads/reportes

            // ✅ Cancelación
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('cancellation_started_at')->nullable();

            // ✅ Error handling
            $table->json('error_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('retry_count')->default(0);

            // ✅ Metadata y audit
            $table->string('created_by')->nullable(); // Usuario que inició
            $table->json('metadata')->nullable();     // Datos adicionales

            $table->timestamps();

            // ✅ Índices optimizados para queries frecuentes
            $table->index(['project_id', 'status']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['type', 'status']);
            $table->index('active_jobs');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unified_batches');
    }
};
