<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El contenedor Docker que más CPU consumía al momento de la muestra:
// dockerd solo dice "Docker trabajó"; esto dice CUÁL contenedor fue.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->string('top_container')->nullable()->after('top_mem_pct');
            $table->float('top_container_pct')->nullable()->after('top_container');
        });
    }

    public function down(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->dropColumn(['top_container', 'top_container_pct']);
        });
    }
};
