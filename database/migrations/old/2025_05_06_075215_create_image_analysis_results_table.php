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
        Schema::create('image_analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('image_id')->constrained()->onDelete('cascade')->unique();
            $table->integer('rows')->nullable();
            $table->integer('columns')->nullable();
            $table->float('integrity_score')->nullable();
            $table->float('luminosity_score')->nullable();
            $table->integer('microcracks_count')->nullable();
            $table->integer('finger_interruptions_count')->nullable();
            $table->integer('black_edges_count')->nullable();
            $table->integer('cells_with_different_intensity')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_analysis_results');
    }
};
