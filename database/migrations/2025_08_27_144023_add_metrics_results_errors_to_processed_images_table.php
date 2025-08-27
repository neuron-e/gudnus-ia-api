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
        Schema::table('processed_images', function (Blueprint $table) {
            $table->json('metrics')->nullable()->after('public_view_enabled');
            $table->json('results')->nullable()->after('metrics');
            $table->json('errors')->nullable()->after('results');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->dropColumn('metrics');
            $table->dropColumn('results');
            $table->dropColumn('errors');
        });
    }
};
