<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Trazabilidad de las incidencias: cuántas veces se resolvió y volvió, y un
// registro de eventos (detectada, reapareció, resuelta, movida a mano…) con
// su hora exacta, para saber la historia de cada problema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('perf_issues', function (Blueprint $table) {
            $table->unsignedInteger('reopened_count')->default(0)->after('occurrences');
        });

        Schema::create('perf_issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perf_issue_id')->constrained()->cascadeOnDelete();
            $table->string('type');            // detectada|reaparecio|resuelta_auto|movida
            $table->string('note')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['perf_issue_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perf_issue_events');
        Schema::table('perf_issues', function (Blueprint $table) {
            $table->dropColumn('reopened_count');
        });
    }
};
