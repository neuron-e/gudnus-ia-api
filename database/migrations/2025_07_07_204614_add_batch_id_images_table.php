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
        // Verificar y actualizar image_batches con los campos necesarios
        Schema::table('image_batches', function (Blueprint $table) {
            // Verificar si dispatched_total existe, si no, crearlo
            if (!Schema::hasColumn('image_batches', 'dispatched_total')) {
                $table->integer('dispatched_total')->default(0)->after('total');
            }

            // Verificar si expected_total existe, si no, crearlo
            if (!Schema::hasColumn('image_batches', 'expected_total')) {
                $table->integer('expected_total')->nullable()->after('dispatched_total');
            }

            // Verificar si temp_path existe, si no, crearlo
            if (!Schema::hasColumn('image_batches', 'temp_path')) {
                $table->string('temp_path', 500)->nullable()->after('error_messages');
            }
        });

        // Verificar si is_counted existe en images, si no, crearlo
        if (!Schema::hasColumn('images', 'is_counted')) {
            Schema::table('images', function (Blueprint $table) {
                $table->boolean('is_counted')->default(false)->after('status');
            });
        }

        // Actualizar batch 55 problemático si existe
        if (DB::table('image_batches')->where('id', 55)->exists()) {
            DB::table('image_batches')
                ->where('id', 55)
                ->update([
                    'dispatched_total' => 419,
                    'expected_total' => 419,
                    'processed' => DB::raw('LEAST(processed, total)'),
                    'errors' => DB::raw('GREATEST(0, processed - total)'),
                    'status' => DB::raw("CASE WHEN processed > total THEN 'completed_with_errors' ELSE 'completed' END")
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('image_batches', function (Blueprint $table) {
            $columns = ['dispatched_total', 'expected_total', 'temp_path'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('image_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('images', 'is_counted')) {
            Schema::table('images', function (Blueprint $table) {
                $table->dropColumn('is_counted');
            });
        }
    }
};
