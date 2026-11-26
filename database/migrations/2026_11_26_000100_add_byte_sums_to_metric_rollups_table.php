<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_rollups', function (Blueprint $table) {
            // Nullable on purpose: rollup rows written before this migration
            // have no exact byte history. The series API falls back to the
            // percentage columns for those rows instead of reporting zero.
            $table->unsignedBigInteger('mem_used_bytes_sum')->nullable()->after('mem_used_pct_sum');
            $table->unsignedBigInteger('disk_used_bytes_sum')->nullable()->after('disk_used_pct_sum');
        });
    }

    public function down(): void
    {
        Schema::table('metric_rollups', function (Blueprint $table) {
            $table->dropColumn([
                'mem_used_bytes_sum',
                'disk_used_bytes_sum',
            ]);
        });
    }
};
