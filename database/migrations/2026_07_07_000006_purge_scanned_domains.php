<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Limpieza única: elimina los "dominios" autodetectados por el escaneo de
     * servidores (source=scan). Eran falsos positivos hallados en configuraciones
     * (p. ej. nginx.com, tar.xz, docker.internal). El módulo de dominios solo
     * administra los sincronizados de GoDaddy o creados a mano, así que estos no
     * aportan valor. Los dominios administrados (godaddy/manual) no se tocan.
     */
    public function up(): void
    {
        DB::table('domains')->where('source', 'scan')->delete();
    }

    public function down(): void
    {
        // No reversible: eran datos autogenerados sin valor.
    }
};
