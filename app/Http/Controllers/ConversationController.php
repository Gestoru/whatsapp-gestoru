<?php

namespace App\Http\Controllers;

use App\Services\Base44Service;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private Base44Service $base44) {}

    public function index(): JsonResponse
    {
        try {
            $result = $this->base44->listEntities('Conversation', [], [
                ['field' => 'last_message_time', 'direction' => 'desc'],
            ]);

            $conversations = $result['entities'] ?? $result;
            return response()->json($conversations);
        } catch (RequestException $e) {
            return $this->error($e);
        }
    }

    public function toggleAi(Request $request, string $conversationId): JsonResponse
    {
        $request->validate(['ai_mode' => 'required|boolean']);

        try {
            $conversation = $this->base44->updateEntity(
                'Conversation',
                $conversationId,
                ['ai_mode' => $request->boolean('ai_mode')]
            );
            return response()->json($conversation);
        } catch (RequestException $e) {
            return $this->error($e);
        }
    }

    public function clearUnread(string $conversationId): JsonResponse
    {
        try {
            $this->base44->updateEntity('Conversation', $conversationId, ['unread_count' => 0]);
            return response()->json(['message' => 'Unread count cleared']);
        } catch (RequestException $e) {
            return $this->error($e);
        }
    }

    private function error(RequestException $e): JsonResponse
    {
        return response()->json(['error' => 'Base44 error: ' . $e->getMessage()], 502);
    }
}
