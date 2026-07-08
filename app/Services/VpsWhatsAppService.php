<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Crypt;
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

        if ($apiKey = self::apiKey()) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $this->client = new Client([
            'base_uri' => rtrim((string) self::apiUrl(), '/') . '/',
            'headers'  => $headers,
            'timeout'  => 20,
        ]);
    }

    /** URL del servidor de WhatsApp: primero el panel, luego el .env. */
    public static function apiUrl(): ?string
    {
        return Setting::get('wa_api_url') ?: config('services.vps.api_url');
    }

    /** Clave del servidor de WhatsApp (guardada cifrada en el panel). */
    public static function apiKey(): ?string
    {
        if ($enc = Setting::get('wa_api_key')) {
            try {
                return Crypt::decryptString($enc);
            } catch (\Throwable $e) {
                // si falla, cae al .env
            }
        }

        return config('services.vps.api_key');
    }

    /** Guarda la conexión (URL y clave) desde el panel. */
    public static function saveConnection(?string $url, ?string $key): void
    {
        if ($url !== null) {
            Setting::put('wa_api_url', trim($url));
        }
        if (! empty($key)) {
            Setting::put('wa_api_key', Crypt::encryptString(trim($key)));
        }
    }

    /** Estado del servidor de WhatsApp (conectado / esperando QR). */
    public function status(): array
    {
        try {
            $r = $this->client->get('status');

            return json_decode($r->getBody()->getContents(), true) ?? [];
        } catch (\Throwable $e) {
            return ['connected' => false, 'error' => $e->getMessage()];
        }
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
