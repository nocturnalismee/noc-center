<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_details', function (Blueprint $table) {
            $table->foreignId('sample_id')->primary()->constrained('metric_samples')->cascadeOnDelete();
            $table->json('panel_metrics')->nullable();
            $table->json('extensions')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_details');
    }
};
