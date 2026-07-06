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
            $table->string('name');
            $table->string('provider')->default('contabo'); // contabo, winhosting, otro
            $table->string('host');                          // IP o dominio
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username')->default('root');
            $table->string('auth_type')->default('password'); // password | key
            $table->text('password')->nullable();             // cifrado
            $table->text('private_key')->nullable();          // cifrado
            $table->text('key_passphrase')->nullable();       // cifrado
            $table->string('color')->default('#6366f1');      // acento visual
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
