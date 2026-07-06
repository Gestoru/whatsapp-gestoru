<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\ServerAdminController;
use App\Http\Middleware\ServerAdminAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Panel de administración de servidores ────────────────────────────────────
Route::prefix('admin')->group(function () {

    // Login
    Route::get('/login', [AuthController::class, 'showLogin'])->name('admin.login');
    Route::post('/login', [AuthController::class, 'login'])->name('admin.login.post');
    Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout');

    // Zona protegida
    Route::middleware(ServerAdminAuth::class)->group(function () {
        Route::get('/', [ServerAdminController::class, 'dashboard'])->name('admin.dashboard');

        // Gestión de servidores (base de datos)
        Route::get('/servers/create', [ServerAdminController::class, 'create'])->name('admin.servers.create');
        Route::post('/servers', [ServerAdminController::class, 'store'])->name('admin.servers.store');
        Route::get('/servers/{server}/edit', [ServerAdminController::class, 'edit'])->name('admin.servers.edit');
        Route::put('/servers/{server}', [ServerAdminController::class, 'update'])->name('admin.servers.update');
        Route::delete('/servers/{server}', [ServerAdminController::class, 'destroy'])->name('admin.servers.destroy');

        // Monitoreo por servidor
        Route::get('/s/{key}', [ServerAdminController::class, 'show'])->name('admin.server.show');
        Route::get('/s/{key}/folders', [ServerAdminController::class, 'folders'])->name('admin.server.folders');
        Route::get('/s/{key}/test', [ServerAdminController::class, 'test'])->name('admin.server.test');
    });
});
