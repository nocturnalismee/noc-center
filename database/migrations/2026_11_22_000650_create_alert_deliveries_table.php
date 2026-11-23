<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('alert_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('event')->default('opened');
            $table->string('status');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['alert_id', 'channel', 'event']);
            $table->index(['channel', 'status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
