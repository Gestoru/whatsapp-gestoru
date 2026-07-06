<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->date('paid_until')->nullable();       // plan vigente hasta / próxima fecha de pago
            $table->decimal('monthly_cost', 8, 2)->nullable();
            $table->string('renewal_url', 500)->nullable(); // enlace para pagar/renovar
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['paid_until', 'monthly_cost', 'renewal_url']);
        });
    }
};
