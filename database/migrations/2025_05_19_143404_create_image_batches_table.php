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
        Schema::create('image_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->onDelete('cascade');
            $table->string('type')->default('zip-mapping'); // zip-mapping, zip-auto, single-upload, etc.
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->integer('errors')->default(0);
            $table->json('error_messages')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'error'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_batches');
    }
};
