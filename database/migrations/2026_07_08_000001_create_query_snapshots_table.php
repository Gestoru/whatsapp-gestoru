<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Histórico ligero de las consultas top de cada servidor. Permite comparar
// una consulta con su estado anterior (¿mejoró tras optimizarla? ¿empeoró?
// ¿desapareció del ranking?) y darle seguimiento en el tiempo.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('digest', 64);              // huella única de la consulta (MySQL DIGEST)
            $table->string('db')->nullable();
            $table->text('query_preview')->nullable(); // texto normalizado (para mostrar)
            $table->float('total_s')->default(0);
            $table->unsignedBigInteger('execs')->default(0);
            $table->float('avg_ms')->default(0);
            $table->unsignedBigInteger('rows_ratio')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->index(['server_id', 'digest', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('query_snapshots');
    }
};
