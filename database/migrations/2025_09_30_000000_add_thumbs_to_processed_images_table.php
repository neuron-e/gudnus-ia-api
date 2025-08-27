<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('corrected_path');
            $table->string('thumb_url')->nullable()->after('thumb_path');
        });
    }

    public function down(): void
    {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->dropColumn(['thumb_path', 'thumb_url']);
        });
    }
};
