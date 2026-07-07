<?php

use App\Http\Controllers\AlertSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainAdminController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerFileController;
use App\Http\Controllers\StressController;
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

    // Observabilidad por dominio (Fase 1)
    Route::get('/servidores/{server}/dominio', [DomainController::class, 'show'])->name('servers.domain');
    Route::get('/servidores/{server}/analisis', [DashboardController::class, 'analytics'])->name('servers.analytics');
    Route::get('/servidores/{server}/tendencias', [DashboardController::class, 'trends'])->name('servers.trends');
    Route::get('/servidores/{server}/estres', [StressController::class, 'form'])->name('servers.stress');
    Route::post('/servidores/{server}/estres', [StressController::class, 'run'])->name('servers.stress.run');

    // Herramientas de mantenimiento (acciones explícitas)
    Route::post('/herramientas/slowlog', [DashboardController::class, 'enableSlowLogAll'])->name('tools.slowlog');

    // Alertas por WhatsApp
    Route::get('/alertas', [AlertSettingsController::class, 'edit'])->name('alerts');
    Route::put('/alertas', [AlertSettingsController::class, 'update'])->name('alerts.update');
    Route::post('/alertas/probar', [AlertSettingsController::class, 'test'])->name('alerts.test');

    // Administración de dominios (registradores y vencimientos)
    Route::get('/dominios', [DomainAdminController::class, 'index'])->name('domains');
    Route::post('/dominios', [DomainAdminController::class, 'store'])->name('domains.store');
    Route::post('/dominios/escanear', [DomainAdminController::class, 'scan'])->name('domains.scan');
    Route::post('/dominios/{domain}/whois', [DomainAdminController::class, 'whois'])->name('domains.whois');
    Route::get('/dominios/{domain}/editar', [DomainAdminController::class, 'edit'])->name('domains.edit');
    Route::put('/dominios/{domain}', [DomainAdminController::class, 'update'])->name('domains.update');
    Route::delete('/dominios/{domain}', [DomainAdminController::class, 'destroy'])->name('domains.destroy');

    // Métricas y archivos (JSON)
    Route::get('/servidores/{server}/metricas', [DashboardController::class, 'metrics'])->name('servers.metrics');
    Route::get('/servidores/{server}/archivos', [ServerFileController::class, 'list'])->name('servers.files');
    Route::get('/servidores/{server}/archivo', [ServerFileController::class, 'read'])->name('servers.file');
});
