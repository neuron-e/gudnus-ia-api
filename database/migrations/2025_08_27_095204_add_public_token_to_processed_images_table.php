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
            $table->uuid('public_token')->nullable()->index();
            $table->timestamp('public_token_expires_at')->nullable()->index();
            $table->boolean('public_view_enabled')->default(true)->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'public_token_expires_at', 'public_view_enabled']);
        });
    }
};
