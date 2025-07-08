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
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->integer('errors')->default(0)->after('processed_images');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('analysis_batches', function (Blueprint $table) {
            $table->dropColumn('errors');
        });
    }
};
