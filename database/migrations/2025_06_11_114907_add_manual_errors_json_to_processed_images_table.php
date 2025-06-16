<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->json('error_edits_json')->nullable()->after('ai_response_json');
        });
    }

    public function down()
    {
        Schema::table('processed_images', function (Blueprint $table) {
            $table->dropColumn('error_edits_json');
        });
    }
};
