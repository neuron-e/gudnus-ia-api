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
        Schema::table('image_batches', function (Blueprint $table) {
            $table->unsignedInteger('dispatched_total')->default(0)->after('total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn('dispatched_total');
        });
    }
};
