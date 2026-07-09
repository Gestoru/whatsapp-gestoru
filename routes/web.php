<?php

use App\Http\Controllers\AlertSettingsController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainAdminController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerFileController;
use App\Http\Controllers\StressController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

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
    Route::get('/servidores/{server}/analisis', [DashboardController::class, 'analytics'])->name('servers.analytics'); // redirige al panel unificado
    Route::get('/servidores/{server}/analisis-panel', [DashboardController::class, 'analyticsPanel'])->name('servers.analytics.panel');
    Route::get('/servidores/{server}/tendencias', [DashboardController::class, 'trends'])->name('servers.trends');
    Route::get('/servidores/{server}/en-vivo', [DashboardController::class, 'liveVisitors'])->name('servers.live');
    Route::get('/servidores/{server}/consultas', [DashboardController::class, 'queryOptimizer'])->name('servers.queries');
    Route::get('/servidores/{server}/consultas-panel', [DashboardController::class, 'queryOptimizerPanel'])->name('servers.queries.panel');
    Route::get('/servidores/{server}/tablero', [DashboardController::class, 'board'])->name('servers.board');
    Route::get('/servidores/{server}/tablero-panel', [DashboardController::class, 'boardPanel'])->name('servers.board.panel');
    Route::post('/servidores/{server}/tablero/{issue}/mover', [DashboardController::class, 'boardMove'])->name('servers.board.move');

    // Planeación con IA (tablero → «Planear» sobre una consulta MySQL)
    Route::get('/servidores/{server}/tablero/{issue}/plan', [PlanController::class, 'state'])->name('servers.plan.state');
    Route::post('/servidores/{server}/tablero/{issue}/plan/repo', [PlanController::class, 'mapRepo'])->name('servers.plan.repo');
    Route::get('/servidores/{server}/tablero/{issue}/plan/stream', [PlanController::class, 'stream'])->name('servers.plan.stream');
    Route::post('/servidores/{server}/tablero/{issue}/plan/chat', [PlanController::class, 'chat'])->name('servers.plan.chat');
    Route::post('/servidores/{server}/tablero/{issue}/plan/publicar', [PlanController::class, 'publish'])->name('servers.plan.publish');
    Route::post('/ia/clave', [PlanController::class, 'saveAiKey'])->name('ai.key');
    Route::get('/servidores/{server}/estres', [StressController::class, 'form'])->name('servers.stress');
    Route::post('/servidores/{server}/estres', [StressController::class, 'run'])->name('servers.stress.run');

    // Herramientas de mantenimiento (acciones explícitas)
    Route::post('/herramientas/slowlog', [DashboardController::class, 'enableSlowLogAll'])->name('tools.slowlog');

    // Integración con Contabo (estado y plan de los VPS)
    Route::post('/contabo/conectar', [ProviderController::class, 'connectContabo'])->name('contabo.connect');
    Route::post('/contabo/sincronizar', [ProviderController::class, 'syncContabo'])->name('contabo.sync');
    Route::post('/servidores/{server}/contabo/{action}', [ProviderController::class, 'serverAction'])
        ->whereIn('action', ['start', 'stop', 'restart'])->name('contabo.action');

    // Módulo Repositorios (GitHub)
    Route::get('/repositorios', [RepositoryController::class, 'index'])->name('repositories');
    Route::post('/repositorios/sincronizar', [RepositoryController::class, 'sync'])->name('repositories.sync');
    Route::post('/repositorios/github/conectar', [RepositoryController::class, 'connect'])->name('repositories.github.connect');
    Route::put('/repositorios/{repository}/nota', [RepositoryController::class, 'updateNote'])->name('repositories.note');

    // Centro de configuración (unifica todos los módulos de ajustes)
    Route::get('/configuracion', [ConfigController::class, 'index'])->name('config');

    // Alertas por WhatsApp
    Route::get('/alertas', [AlertSettingsController::class, 'edit'])->name('alerts');
    Route::put('/alertas', [AlertSettingsController::class, 'update'])->name('alerts.update');
    Route::post('/alertas/probar', [AlertSettingsController::class, 'test'])->name('alerts.test');
    // Conexión del servidor de WhatsApp (URL/clave + QR + estado)
    Route::post('/alertas/whatsapp', [AlertSettingsController::class, 'saveWhatsApp'])->name('alerts.wa.save');
    Route::get('/alertas/whatsapp/estado', [AlertSettingsController::class, 'waStatus'])->name('alerts.wa.status');
    Route::post('/alertas/whatsapp/qr', [AlertSettingsController::class, 'waRequestQr'])->name('alerts.wa.qr');
    Route::post('/alertas/whatsapp/codigo', [AlertSettingsController::class, 'waPair'])->name('alerts.wa.pair');
    Route::post('/alertas/whatsapp/desconectar', [AlertSettingsController::class, 'waDisconnect'])->name('alerts.wa.disconnect');

    // Administración de dominios (registradores y vencimientos)
    Route::get('/dominios', [DomainAdminController::class, 'index'])->name('domains');
    Route::post('/dominios', [DomainAdminController::class, 'store'])->name('domains.store');
    Route::post('/dominios/escanear', [DomainAdminController::class, 'scan'])->name('domains.scan');
    Route::post('/dominios/godaddy/conectar', [DomainAdminController::class, 'connectGoDaddy'])->name('domains.godaddy.connect');
    Route::post('/dominios/godaddy/sincronizar', [DomainAdminController::class, 'syncGoDaddy'])->name('domains.godaddy.sync');
    Route::post('/dominios/{domain}/whois', [DomainAdminController::class, 'whois'])->name('domains.whois');
    Route::post('/dominios/{domain}/archivar', [DomainAdminController::class, 'deactivate'])->name('domains.deactivate');
    Route::post('/dominios/{domain}/reactivar', [DomainAdminController::class, 'reactivate'])->name('domains.reactivate');
    Route::get('/dominios/{domain}/editar', [DomainAdminController::class, 'edit'])->name('domains.edit');
    Route::put('/dominios/{domain}', [DomainAdminController::class, 'update'])->name('domains.update');
    Route::delete('/dominios/{domain}', [DomainAdminController::class, 'destroy'])->name('domains.destroy');

    // Métricas y archivos (JSON)
    Route::get('/servidores/{server}/metricas', [DashboardController::class, 'metrics'])->name('servers.metrics');
    Route::get('/servidores/{server}/mysql-vivo', [DashboardController::class, 'mysqlLive'])->name('servers.mysql.live');
    Route::get('/servidores/{server}/archivos', [ServerFileController::class, 'list'])->name('servers.files');
    Route::get('/servidores/{server}/archivo', [ServerFileController::class, 'read'])->name('servers.file');
});
