<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('resolution_seconds');
            $table->timestamp('bucket_start');
            $table->unsignedInteger('samples');

            $table->decimal('cpu_usage_sum', 12, 2);
            $table->decimal('cpu_iowait_sum', 12, 2);
            $table->decimal('mem_used_pct_sum', 12, 2);
            $table->decimal('disk_used_pct_sum', 12, 2);
            $table->decimal('load1_sum', 14, 3);
            $table->unsignedBigInteger('net_rx_bps_sum');
            $table->unsignedBigInteger('net_tx_bps_sum');

            $table->decimal('cpu_usage_min', 5, 2);
            $table->decimal('cpu_usage_max', 5, 2);
            $table->decimal('mem_used_pct_min', 5, 2);
            $table->decimal('mem_used_pct_max', 5, 2);
            $table->decimal('disk_used_pct_min', 5, 2);
            $table->decimal('disk_used_pct_max', 5, 2);
            $table->decimal('load1_max', 8, 3);

            $table->timestamps();

            $table->unique(['server_id', 'resolution_seconds', 'bucket_start']);
            $table->index(['server_id', 'resolution_seconds', 'bucket_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_rollups');
    }
};
