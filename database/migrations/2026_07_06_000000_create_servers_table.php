<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // identificador corto (slug)
            $table->string('name');                    // nombre visible
            $table->string('group')->default('vps');   // vps | winhosting | otros
            $table->string('host');                    // IP o dominio
            $table->unsignedInteger('port')->default(22);
            $table->string('username')->default('root');
            $table->string('auth_method')->default('key'); // key | password
            $table->text('private_key_path')->nullable();  // ruta a la llave privada
            $table->text('password')->nullable();          // cifrada (cast encrypted)
            $table->string('base_path')->nullable();        // carpeta raíz a listar
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
