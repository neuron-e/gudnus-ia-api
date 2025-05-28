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
        Schema::table('image_analysis_results', function (Blueprint $table) {
            $table->float('uniformity_score')->nullable()->after('luminosity_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_analysis_results', function (Blueprint $table) {
            $table->dropColumn('uniformity_score');
        });
    }
};
