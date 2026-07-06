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
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sampled_at')->index();
            $table->unsignedTinyInteger('cpu_pct')->nullable();
            $table->unsignedBigInteger('mem_used')->nullable();
            $table->unsignedBigInteger('mem_total')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->decimal('load1', 6, 2)->nullable();
            // Culpable del momento (para cazar picos)
            $table->string('top_cpu_cmd', 120)->nullable();
            $table->decimal('top_cpu_pct', 5, 1)->nullable();
            $table->string('top_mem_cmd', 120)->nullable();
            $table->decimal('top_mem_pct', 5, 1)->nullable();
            $table->timestamps();

            $table->index(['server_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
