<?php

use App\Http\Controllers\AgentConfigController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\ApiTokenMiddleware;
use App\Http\Middleware\WebhookSecretMiddleware;
use Illuminate\Support\Facades\Route;

// ── Webhook del VPS (autenticado con x-webhook-secret) ───────────────────────
Route::post('/whatsapp/webhook-from-vps', [WebhookController::class, 'handle'])
    ->middleware(WebhookSecretMiddleware::class);

// ── Endpoints protegidos con API Token de Laravel ─────────────────────────────
Route::middleware(ApiTokenMiddleware::class)->group(function () {

    // Configuración del agente
    Route::get('/agent/config', [AgentConfigController::class, 'show']);
    Route::put('/agent/config', [AgentConfigController::class, 'update']);

    // Conexión WhatsApp
    Route::post('/whatsapp/connect/request-qr', [AgentConfigController::class, 'requestQr']);
    Route::post('/whatsapp/connect/disconnect',  [AgentConfigController::class, 'disconnect']);

    // Conversaciones
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::patch('/conversations/{conversationId}/toggle-ai',    [ConversationController::class, 'toggleAi']);
    Route::post('/conversations/{conversationId}/clear-unread',  [ConversationController::class, 'clearUnread']);

    // Mensajes
    Route::get('/conversations/{conversationId}/messages',  [MessageController::class, 'index']);
    Route::post('/conversations/{conversationId}/messages', [MessageController::class, 'store']);
});
