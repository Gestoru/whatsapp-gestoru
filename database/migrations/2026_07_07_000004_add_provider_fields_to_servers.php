<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('provider_status')->nullable();     // running, stopped, etc.
            $table->string('provider_product')->nullable();    // plan / producto
            $table->string('provider_region')->nullable();     // región
            $table->string('provider_instance_id')->nullable();
            $table->timestamp('provider_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'provider_product', 'provider_region', 'provider_instance_id', 'provider_synced_at']);
        });
    }
};
