<?php

namespace App\Http\Controllers;

use App\Services\Base44Service;
use App\Services\VpsWhatsAppService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MessageController extends Controller
{
    public function __construct(
        private Base44Service $base44,
        private VpsWhatsAppService $vps,
    ) {}

    public function index(string $conversationId): JsonResponse
    {
        try {
            $result = $this->base44->listEntities('Message', [
                ['field' => 'conversation_id', 'operator' => '=', 'value' => $conversationId],
            ], [
                ['field' => 'timestamp', 'direction' => 'asc'],
            ], 200);

            return response()->json($result['entities'] ?? $result);
        } catch (RequestException $e) {
            return $this->error($e);
        }
    }

    public function store(Request $request, string $conversationId): JsonResponse
    {
        $request->validate([
            'content'    => 'required|string',
            'media_type' => 'nullable|string|in:text,image,audio,video,document',
            'media_url'  => 'nullable|string',
            'media_name' => 'nullable|string',
        ]);

        try {
            $conversation = $this->base44->getEntity('Conversation', $conversationId);
        } catch (RequestException $e) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $mediaType = $request->input('media_type', 'text');
        $content   = $request->input('content');
        $mediaUrl  = $request->input('media_url');
        $mediaName = $request->input('media_name');

        try {
            $this->vps->sendMessage(
                $conversation['contact_phone'],
                $content,
                $mediaType,
                $mediaUrl,
                $mediaName,
            );
        } catch (RequestException $e) {
            return response()->json(['error' => 'VPS error: ' . $e->getMessage()], 502);
        }

        try {
            $now = Carbon::now()->toIso8601String();

            $message = $this->base44->createEntity('Message', [
                'conversation_id' => $conversationId,
                'sender'          => 'human',
                'content'         => $content,
                'media_type'      => $mediaType,
                'media_url'       => $mediaUrl,
                'media_name'      => $mediaName,
                'status'          => 'sent',
                'timestamp'       => $now,
            ]);

            $this->base44->updateEntity('Conversation', $conversationId, [
                'last_message'      => $content,
                'last_message_time' => $now,
            ]);

            return response()->json($message, 201);
        } catch (RequestException $e) {
            return $this->error($e);
        }
    }

    private function error(RequestException $e): JsonResponse
    {
        return response()->json(['error' => 'Base44 error: ' . $e->getMessage()], 502);
    }
}
