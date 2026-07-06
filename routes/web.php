<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerFileController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard.index'));

// ── Login del dashboard ─────────────────────────────────────────────────────
Route::get('/panel/login', [DashboardController::class, 'loginForm'])->name('dashboard.login');
Route::post('/panel/login', [DashboardController::class, 'login'])->name('dashboard.login.attempt');
Route::post('/panel/logout', [DashboardController::class, 'logout'])->name('dashboard.logout');

// ── Dashboard de infraestructura (protegido) ────────────────────────────────
Route::middleware('dashboard.auth')->prefix('panel')->name('dashboard.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('index');

    // CRUD de servidores
    Route::get('/servidores/nuevo', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servidores', [ServerController::class, 'store'])->name('servers.store');
    Route::get('/servidores/{server}', [DashboardController::class, 'show'])->name('servers.show');
    Route::get('/servidores/{server}/editar', [ServerController::class, 'edit'])->name('servers.edit');
    Route::put('/servidores/{server}', [ServerController::class, 'update'])->name('servers.update');
    Route::delete('/servidores/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servidores/{server}/probar', [ServerController::class, 'test'])->name('servers.test');

    // Métricas y archivos (JSON)
    Route::get('/servidores/{server}/metricas', [DashboardController::class, 'metrics'])->name('servers.metrics');
    Route::get('/servidores/{server}/archivos', [ServerFileController::class, 'list'])->name('servers.files');
    Route::get('/servidores/{server}/archivo', [ServerFileController::class, 'read'])->name('servers.file');
});
