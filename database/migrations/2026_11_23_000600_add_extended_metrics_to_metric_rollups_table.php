<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_rollups', function (Blueprint $table) {
            // Additive columns for the long-range chart series (spec §7.6).
            // Defaults of zero keep rows written before this migration
            // readable. `noc:rollup --backfill-days=N` can only rebuild the
            // raw-retained history (metric_samples, 14 days); older rollups
            // stay zero because their source samples are already pruned.
            $table->decimal('swap_used_pct_sum', 12, 2)->default(0);
            $table->decimal('disk_inode_pct_sum', 12, 2)->default(0);
            $table->unsignedBigInteger('processes_running_sum')->default(0);
            $table->unsignedBigInteger('processes_blocked_sum')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('metric_rollups', function (Blueprint $table) {
            $table->dropColumn([
                'swap_used_pct_sum',
                'disk_inode_pct_sum',
                'processes_running_sum',
                'processes_blocked_sum',
            ]);
        });
    }
};
