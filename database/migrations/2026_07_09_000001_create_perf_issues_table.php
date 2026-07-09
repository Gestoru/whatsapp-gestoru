<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tablero de incidencias de rendimiento (Kanban). Unifica picos de CPU y
// consultas MySQL pesadas como "incidencias" con estado, para darles
// seguimiento: por revisar → en optimización → resuelta / no aplica.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perf_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                 // cpu_peak | mysql_query
            $table->string('signature');            // clave estable (contenedor o digest)
            $table->string('title');
            $table->text('summary')->nullable();    // descripción corta legible
            $table->text('ai_cause')->nullable();    // causa probable (análisis automático)
            $table->text('ai_prompt')->nullable();   // contexto listo para pegar en una IA
            $table->string('severity')->default('media'); // critica | alta | media
            $table->string('status')->default('por_revisar'); // por_revisar|optimizando|resuelta|aceptada
            $table->boolean('status_manual')->default(false); // el usuario fijó el estado
            $table->json('metrics')->nullable();     // datos para la tarjeta
            $table->unsignedInteger('occurrences')->default(1);
            $table->float('score')->default(0);      // para ordenar (cpu% o segundos)
            $table->string('link')->nullable();      // enlace a detalle (p. ej. /consultas)
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['server_id', 'kind', 'signature']);
            $table->index(['server_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_issues');
    }
};
