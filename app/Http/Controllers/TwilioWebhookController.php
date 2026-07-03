<?php

namespace App\Http\Controllers;

use App\Services\Base44Service;
use App\Services\Base44WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Recibe los webhooks de Twilio para WhatsApp:
 *   - Mensajes entrantes de contactos.
 *   - Callbacks de estado de mensajes salientes (queued/sent/delivered/read/failed).
 *
 * Twilio envía los datos como application/x-www-form-urlencoded.
 */
class TwilioWebhookController extends Controller
{
    public function __construct(
        private Base44Service $base44,
        private Base44WebhookService $base44Webhook,
    ) {}

    /** Mensajes entrantes de WhatsApp (Twilio Messaging webhook). */
    public function incoming(Request $request): JsonResponse
    {
        $phone   = $this->stripWhatsApp($request->input('From'));
        $name    = $request->input('ProfileName') ?: $phone;
        $content = (string) $request->input('Body', '');
        $now     = Carbon::now()->toIso8601String();

        if (!$phone) {
            return response()->json(['error' => 'Missing From'], 422);
        }

        Log::info('Webhook received from Twilio', ['from' => $phone]);

        [$mediaType, $mediaUrl, $mediaName] = $this->extractMedia($request);

        $conversation = $this->base44->findConversationByPhone($phone);

        if (!$conversation) {
            $conversation = $this->base44->createEntity('Conversation', [
                'contact_phone'     => $phone,
                'contact_name'      => $name,
                'contact_avatar'    => '',
                'last_message'      => $content,
                'last_message_time' => $now,
                'unread_count'      => 1,
                'ai_mode'           => true,
                'status'            => 'active',
            ]);
        } else {
            $this->base44->updateEntity('Conversation', $conversation['id'], [
                'last_message'      => $content,
                'last_message_time' => $now,
                'unread_count'      => ($conversation['unread_count'] ?? 0) + 1,
            ]);
        }

        $this->base44->createEntity('Message', [
            'conversation_id' => $conversation['id'],
            'sender'          => 'contact',
            'content'         => $content,
            'media_type'      => $mediaType,
            'media_url'       => $mediaUrl,
            'media_name'      => $mediaName,
            'status'          => 'delivered',
            'timestamp'       => $now,
        ]);

        // Respondemos TwiML vacío para que Twilio no reintente.
        return response()->json(['message' => 'Incoming message processed']);
    }

    /** Callback de estado de un mensaje saliente. */
    public function status(Request $request): JsonResponse
    {
        $messageSid = $request->input('MessageSid') ?? $request->input('SmsSid');
        $status     = $request->input('MessageStatus') ?? $request->input('SmsStatus');

        if (!$messageSid || !$status) {
            return response()->json(['error' => 'Missing MessageSid or MessageStatus'], 422);
        }

        $this->base44Webhook->notify('message_status_update', [
            'provider_message_id' => $messageSid,
            'status'              => $status,
        ]);

        return response()->json(['message' => 'Status update processed']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Quita el prefijo whatsapp: que Twilio antepone a los números. */
    private function stripWhatsApp(?string $number): ?string
    {
        if (!$number) {
            return null;
        }

        return str_starts_with($number, 'whatsapp:')
            ? substr($number, strlen('whatsapp:'))
            : $number;
    }

    /**
     * Extrae el primer adjunto de un webhook de Twilio.
     *
     * @return array{0:string,1:?string,2:?string} [media_type, media_url, media_name]
     */
    private function extractMedia(Request $request): array
    {
        if ((int) $request->input('NumMedia', 0) < 1) {
            return ['text', null, null];
        }

        $url         = $request->input('MediaUrl0');
        $contentType = (string) $request->input('MediaContentType0', '');

        $type = match (true) {
            str_starts_with($contentType, 'image/') => 'image',
            str_starts_with($contentType, 'audio/') => 'audio',
            str_starts_with($contentType, 'video/') => 'video',
            default                                  => 'document',
        };

        return [$type, $url, $contentType];
    }
}
