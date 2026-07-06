<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();               // dominio raíz: gestoru.com
            $table->string('registrar')->default('desconocido'); // godaddy, ionos, winhosting, otro
            $table->date('expires_at')->nullable();          // vencimiento del dominio
            $table->string('renewal_url', 500)->nullable();  // enlace para renovar/pagar
            $table->text('notes')->nullable();
            $table->string('source')->default('manual');     // manual | scan
            $table->timestamp('whois_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hostnames', function (Blueprint $table) {
            $table->id();
            $table->string('hostname')->unique();            // app.gestoru.com
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostnames');
        Schema::dropIfExists('domains');
    }
};
