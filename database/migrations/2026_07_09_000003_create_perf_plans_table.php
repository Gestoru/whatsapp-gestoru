<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Planes de optimización generados con IA para las incidencias del tablero:
// a qué repositorio de GitHub pertenece la consulta, qué archivos se
// investigaron, el plan en Markdown, la conversación de ajustes con la IA y
// la referencia (rama/PR) cuando la solución se sube a GitHub. Todo queda
// guardado para conservar la trazabilidad completa de cada optimización.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('perf_plans')) {
            return;
        }

        Schema::create('perf_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perf_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository_full_name')->nullable();   // se conserva aunque borren el repo
            $table->string('status')->default('pendiente');        // pendiente|investigando|generando|listo|error
            $table->longText('plan')->nullable();                  // plan en Markdown
            $table->json('investigation')->nullable();             // tablas detectadas + archivos leídos
            $table->json('chat')->nullable();                      // conversación de ajustes con la IA
            $table->string('model')->nullable();                   // modelo de IA usado
            $table->text('error')->nullable();
            $table->string('github_branch')->nullable();
            $table->string('github_pr_url')->nullable();
            $table->string('github_file_url')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();

            $table->index('perf_issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_plans');
    }
};
