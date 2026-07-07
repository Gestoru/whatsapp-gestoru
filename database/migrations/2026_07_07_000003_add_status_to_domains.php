<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->string('status')->nullable();       // ACTIVE, EXPIRED, PENDING, etc.
            $table->boolean('auto_renew')->nullable();   // renovación automática
            $table->timestamp('synced_at')->nullable();  // última sincronización con el registrador
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['status', 'auto_renew', 'synced_at']);
        });
    }
};
