<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('owner');            // organización o usuario
            $table->string('name');
            $table->string('full_name');
            $table->text('description')->nullable();
            $table->string('language')->nullable();
            $table->string('visibility')->default('public'); // public / private
            $table->string('html_url');
            $table->string('default_branch')->nullable();
            $table->unsignedInteger('stars')->default(0);
            $table->unsignedInteger('forks')->default(0);
            $table->unsignedInteger('open_issues')->default(0);
            $table->json('topics')->nullable();
            $table->boolean('archived')->default(false);
            $table->boolean('is_fork')->default(false);
            $table->timestamp('pushed_at')->nullable();
            $table->text('notes')->nullable();  // documentación editable por el usuario
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('owner');
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
