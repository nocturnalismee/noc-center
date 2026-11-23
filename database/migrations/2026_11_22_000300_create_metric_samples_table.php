<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('collected_at');
            $table->timestamp('received_at');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('agent_version')->nullable();
            $table->unsignedSmallInteger('interval_seconds');

            $table->decimal('cpu_usage_pct', 5, 2);
            $table->decimal('cpu_iowait_pct', 5, 2);
            $table->unsignedSmallInteger('cpu_cores');
            $table->decimal('load1', 8, 3);
            $table->decimal('load5', 8, 3);
            $table->decimal('load15', 8, 3);

            $table->unsignedBigInteger('mem_total_bytes');
            $table->unsignedBigInteger('mem_used_bytes');
            $table->decimal('mem_used_pct', 5, 2);
            $table->unsignedBigInteger('swap_total_bytes');
            $table->unsignedBigInteger('swap_used_bytes');
            $table->decimal('swap_used_pct', 5, 2);

            $table->unsignedBigInteger('disk_total_bytes');
            $table->unsignedBigInteger('disk_used_bytes');
            $table->decimal('disk_used_pct', 5, 2);
            $table->decimal('disk_inode_pct', 5, 2);

            $table->unsignedBigInteger('net_rx_bps');
            $table->unsignedBigInteger('net_tx_bps');
            $table->unsignedInteger('processes_running');
            $table->unsignedInteger('processes_blocked');
            $table->unsignedBigInteger('uptime_seconds');

            $table->unsignedInteger('payload_bytes');
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['server_id', 'collected_at']);
            $table->index(['server_id', 'collected_at']);
            $table->index('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
