<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task_key')->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->string('status', 16)->default('unknown');
            $table->json('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_runs');
    }
};
