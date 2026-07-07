<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->unsignedInteger('mysql_conns')->nullable();     // conexiones abiertas
            $table->unsignedInteger('mysql_running')->nullable();   // consultas ejecutándose
        });
    }

    public function down(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->dropColumn(['mysql_conns', 'mysql_running']);
        });
    }
};
