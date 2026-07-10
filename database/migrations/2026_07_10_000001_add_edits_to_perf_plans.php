<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cambios de código propuestos por la IA para una incidencia: lista de
// ediciones (archivo + fragmento exacto a reemplazar) que el usuario revisa
// como diff y acepta o rechaza antes de subirlas a GitHub.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('perf_plans') || Schema::hasColumn('perf_plans', 'edits')) {
            return;
        }
        Schema::table('perf_plans', function (Blueprint $table) {
            $table->json('edits')->nullable()->after('plan');
            $table->string('code_commit_url')->nullable()->after('github_file_url');
            $table->timestamp('code_pushed_at')->nullable()->after('pushed_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('perf_plans')) {
            return;
        }
        Schema::table('perf_plans', function (Blueprint $table) {
            $table->dropColumn(['edits', 'code_commit_url', 'code_pushed_at']);
        });
    }
};
