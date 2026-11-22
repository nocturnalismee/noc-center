<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('hostname');
            $table->string('panel')->default('none');
            $table->string('group_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('kernel')->nullable();
            $table->string('agent_version')->nullable();
            $table->uuid('agent_uuid')->nullable()->index();
            $table->unsignedSmallInteger('interval_seconds')->default(60);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_sample_at')->nullable();
            $table->timestamp('stale_after')->nullable();
            $table->timestamp('offline_after')->nullable();

            $table->decimal('cpu_usage_pct', 5, 2)->nullable();
            $table->decimal('cpu_iowait_pct', 5, 2)->nullable();
            $table->decimal('load1', 8, 3)->nullable();
            $table->decimal('load5', 8, 3)->nullable();
            $table->decimal('load15', 8, 3)->nullable();
            $table->decimal('mem_used_pct', 5, 2)->nullable();
            $table->decimal('swap_used_pct', 5, 2)->nullable();
            $table->decimal('disk_used_pct', 5, 2)->nullable();
            $table->decimal('disk_inode_pct', 5, 2)->nullable();
            $table->unsignedBigInteger('net_rx_bps')->nullable();
            $table->unsignedBigInteger('net_tx_bps')->nullable();
            $table->unsignedInteger('processes_running')->nullable();
            $table->unsignedInteger('processes_blocked')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();

            $table->string('health')->default('unknown')->index();
            $table->json('issue_codes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->softDeletes();
            $table->timestamps();

            $table->index('hostname');
            $table->index('panel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
