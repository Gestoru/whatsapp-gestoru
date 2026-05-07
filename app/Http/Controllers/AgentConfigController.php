<?php

namespace App\Http\Controllers;

use App\Services\Base44Service;
use App\Services\VpsWhatsAppService;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentConfigController extends Controller
{
    public function __construct(
        private Base44Service $base44,
        private VpsWhatsAppService $vps,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $config = $this->base44->getAgentConfig();

            if (!$config) {
                $config = $this->base44->upsertAgentConfig([]);
            }

            return response()->json($config);
        } catch (RequestException $e) {
            return $this->base44Error($e);
        }
    }

    public function update(Request $request): JsonResponse
    {
        $allowed = [
            'system_prompt', 'company_name', 'welcome_message',
            'escalation_message', 'vision_enabled', 'audio_transcription_enabled',
        ];

        $data = $request->only($allowed);

        if (empty($data)) {
            return response()->json(['error' => 'No valid fields provided'], 422);
        }

        try {
            $config = $this->base44->upsertAgentConfig($data);
            return response()->json($config);
        } catch (RequestException $e) {
            return $this->base44Error($e);
        }
    }

    public function requestQr(): JsonResponse
    {
        try {
            $this->vps->requestQr();
            $this->base44->upsertAgentConfig([
                'connection_status' => 'loading',
                'qr_code_data_url'  => null,
            ]);
            return response()->json(['message' => 'QR generation initiated']);
        } catch (RequestException $e) {
            return $this->vpsError($e);
        }
    }

    public function disconnect(): JsonResponse
    {
        try {
            $this->vps->disconnect();
            $this->base44->upsertAgentConfig([
                'connection_status' => 'disconnected',
                'qr_code_data_url'  => null,
            ]);
            return response()->json(['message' => 'WhatsApp disconnected successfully']);
        } catch (RequestException $e) {
            return $this->vpsError($e);
        }
    }

    public function status(): JsonResponse
    {
        try {
            $config = $this->base44->getAgentConfig();
            return response()->json([
                'connection_status' => $config['connection_status'] ?? 'disconnected',
                'qr_code_data_url'  => $config['qr_code_data_url'] ?? null,
            ]);
        } catch (RequestException $e) {
            return $this->base44Error($e);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function base44Error(RequestException $e): JsonResponse
    {
        return response()->json(['error' => 'Base44 error: ' . $e->getMessage()], 502);
    }

    private function vpsError(RequestException $e): JsonResponse
    {
        return response()->json(['error' => 'VPS error: ' . $e->getMessage()], 502);
    }
}
