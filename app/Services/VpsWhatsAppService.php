<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class VpsWhatsAppService
{
    private Client $client;

    public function __construct()
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        if ($apiKey = config('services.vps.api_key')) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $this->client = new Client([
            'base_uri' => rtrim(config('services.vps.api_url'), '/') . '/',
            'headers'  => $headers,
            'timeout'  => 30,
        ]);
    }

    public function requestQr(): array
    {
        return $this->post('request-qr');
    }

    public function disconnect(): array
    {
        return $this->post('disconnect');
    }

    public function sendMessage(string $phone, string $content, ?string $mediaType = null, ?string $mediaUrl = null, ?string $mediaName = null): array
    {
        $payload = ['phone' => $phone, 'content' => $content];

        if ($mediaType && $mediaType !== 'text') {
            $payload['media_type'] = $mediaType;
            $payload['media_url']  = $mediaUrl;
            $payload['media_name'] = $mediaName;
        }

        return $this->post('send-message', $payload);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function post(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $context = ['endpoint' => $endpoint];
            if ($e->hasResponse()) {
                $context['response'] = $e->getResponse()->getBody()->getContents();
                $context['status']   = $e->getResponse()->getStatusCode();
            }
            Log::error('VPS WhatsApp error', array_merge($context, ['error' => $e->getMessage()]));
            throw $e;
        }
    }
}
