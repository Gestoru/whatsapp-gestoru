<?php

use App\Http\Controllers\AgentConfigController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\TwilioWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\ApiTokenMiddleware;
use App\Http\Middleware\TwilioSignatureMiddleware;
use App\Http\Middleware\WebhookSecretMiddleware;
use Illuminate\Support\Facades\Route;

// ── Webhook del VPS (autenticado con x-webhook-secret) ───────────────────────
Route::post('/whatsapp/webhook-from-vps', [WebhookController::class, 'handle'])
    ->middleware(WebhookSecretMiddleware::class);

// ── Webhooks de Twilio (validados con X-Twilio-Signature) ────────────────────
Route::middleware(TwilioSignatureMiddleware::class)->group(function () {
    Route::post('/whatsapp/twilio/incoming',        [TwilioWebhookController::class, 'incoming']);
    Route::post('/whatsapp/twilio/status-callback', [TwilioWebhookController::class, 'status']);
});

// ── Endpoints protegidos con API Token de Laravel ─────────────────────────────
Route::middleware(ApiTokenMiddleware::class)->group(function () {

    // Configuración del agente
    Route::get('/agent/config', [AgentConfigController::class, 'show']);
    Route::put('/agent/config', [AgentConfigController::class, 'update']);

    // Conexión WhatsApp
    Route::get('/whatsapp/status', [AgentConfigController::class, 'status']);
    Route::post('/whatsapp/connect/request-qr', [AgentConfigController::class, 'requestQr']);
    Route::post('/whatsapp/connect/disconnect',  [AgentConfigController::class, 'disconnect']);

    // Conexión Twilio (verificar credenciales de la cuenta)
    Route::get('/whatsapp/twilio/status', [AgentConfigController::class, 'twilioStatus']);

    // Conversaciones
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::patch('/conversations/{conversationId}/toggle-ai',    [ConversationController::class, 'toggleAi']);
    Route::post('/conversations/{conversationId}/clear-unread',  [ConversationController::class, 'clearUnread']);

    // Mensajes
    Route::get('/conversations/{conversationId}/messages',  [MessageController::class, 'index']);
    Route::post('/conversations/{conversationId}/messages', [MessageController::class, 'store']);
});
