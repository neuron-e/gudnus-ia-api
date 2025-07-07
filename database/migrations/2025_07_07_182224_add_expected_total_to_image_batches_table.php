<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->integer('expected_total')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $table->dropColumn('expected_total');
        });
    }
};
