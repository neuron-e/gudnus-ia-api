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
        Schema::create('zip_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('original_filename');
            $table->string('file_path'); // Ruta del ZIP en storage
            $table->bigInteger('file_size'); // Tamaño en bytes
            $table->enum('status', ['uploaded', 'processing', 'completed', 'failed'])->default('uploaded');
            $table->integer('progress')->nullable(); // Progreso 0-100
            $table->integer('total_files')->nullable(); // Total de archivos en ZIP
            $table->integer('valid_images')->nullable(); // Imágenes válidas encontradas
            $table->longText('images_data')->nullable(); // JSON con datos de imágenes
            $table->text('error_message')->nullable(); // Error si falló
            $table->timestamps();

            // Índices para consultas eficientes
            $table->index(['project_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zip_analyses');
    }
};
