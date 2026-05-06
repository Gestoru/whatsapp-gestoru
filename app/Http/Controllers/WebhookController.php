<?php

namespace App\Http\Controllers;

use App\Services\Base44Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WebhookController extends Controller
{
    public function __construct(private Base44Service $base44) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $type    = $payload['type'] ?? null;

        Log::info('Webhook received from VPS', ['type' => $type]);

        return match ($type) {
            'qr_update'             => $this->handleQrUpdate($payload),
            'status_change'         => $this->handleStatusChange($payload),
            'incoming_message'      => $this->handleIncomingMessage($payload),
            'message_status_update' => $this->handleMessageStatusUpdate($payload),
            default                 => response()->json(['error' => 'Unknown webhook type'], 400),
        };
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    private function handleQrUpdate(array $payload): JsonResponse
    {
        $this->base44->upsertAgentConfig([
            'connection_status' => 'scanning',
            'qr_code_data_url'  => $payload['qr_data_url'] ?? null,
        ]);

        return response()->json(['message' => 'QR updated']);
    }

    private function handleStatusChange(array $payload): JsonResponse
    {
        $status = $payload['status'] ?? 'disconnected';

        $update = ['connection_status' => $status];

        if ($status === 'connected') {
            $update['whatsapp_session'] = $payload['session'] ?? null;
            $update['qr_code_data_url'] = null;
        }

        if ($status === 'disconnected') {
            $update['qr_code_data_url']  = null;
            $update['whatsapp_session']  = null;
        }

        $this->base44->upsertAgentConfig($update);

        return response()->json(['message' => 'Status updated']);
    }

    private function handleIncomingMessage(array $payload): JsonResponse
    {
        $phone     = $payload['phone'] ?? null;
        $name      = $payload['name'] ?? $phone;
        $content   = $payload['content'] ?? '';
        $mediaType = $payload['media_type'] ?? 'text';
        $now       = Carbon::now()->toIso8601String();

        if (!$phone) {
            return response()->json(['error' => 'Missing phone'], 422);
        }

        $conversation = $this->base44->findConversationByPhone($phone);

        if (!$conversation) {
            $conversation = $this->base44->createEntity('Conversation', [
                'contact_phone'     => $phone,
                'contact_name'      => $name,
                'contact_avatar'    => $payload['avatar'] ?? '',
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

        $mediaUrl  = null;
        $mediaName = $payload['media_name'] ?? null;

        if (!empty($payload['media_base64'])) {
            $mediaUrl = $this->saveAndUploadMedia(
                $payload['media_base64'],
                $mediaType,
                $mediaName ?? 'media_' . time(),
            );
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

        return response()->json(['message' => 'Incoming message processed']);
    }

    private function handleMessageStatusUpdate(array $payload): JsonResponse
    {
        $messageId = $payload['message_id'] ?? null;
        $status    = $payload['status'] ?? null;

        if (!$messageId || !$status) {
            return response()->json(['error' => 'Missing message_id or status'], 422);
        }

        $this->base44->updateEntity('Message', $messageId, ['status' => $status]);

        return response()->json(['message' => 'Message status updated']);
    }

    // ── Media helper ──────────────────────────────────────────────────────────

    private function saveAndUploadMedia(string $base64, string $mediaType, string $name): ?string
    {
        $mimeMap = [
            'image'    => 'image/jpeg',
            'audio'    => 'audio/ogg',
            'video'    => 'video/mp4',
            'document' => 'application/octet-stream',
        ];

        $extMap = ['image' => 'jpg', 'audio' => 'ogg', 'video' => 'mp4', 'document' => 'bin'];

        $mimeType  = $mimeMap[$mediaType]  ?? 'application/octet-stream';
        $extension = $extMap[$mediaType]   ?? 'bin';
        $fileName  = $name . '_' . uniqid() . '.' . $extension;
        $path      = 'whatsapp_media/' . $fileName;

        // Strip data URI prefix if present
        $data = preg_replace('/^data:[^;]+;base64,/', '', $base64);
        Storage::disk('public')->put($path, base64_decode($data));

        $fullPath = Storage::disk('public')->path($path);

        $url = $this->base44->uploadFile($fullPath, $mimeType, $fileName);

        Storage::disk('public')->delete($path);

        return $url;
    }
}
