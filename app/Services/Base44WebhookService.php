<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Base44WebhookService
{
    public function notify(string $event, array $payload = []): bool
    {
        $url = config('services.base44.webhook_url');
        $secret = config('services.base44.webhook_secret');

        if (!$url || !$secret) {
            Log::warning('Base44 webhook no configurado');
            return false;
        }

        try {
            $appId = config('services.base44.app_id') ?: (env('BASE44_APP_ID') ?: '69f9568059d0991e82e61dcd');

            $response = Http::withHeaders([
                'X-Webhook-Secret' => $secret,
                'Base44-App-Id'    => $appId,
                'Content-Type'     => 'application/json',
                'Accept'           => 'application/json',
            ])->timeout(10)->post($url, array_merge(['event' => $event], $payload));

            if (!$response->successful()) {
                Log::error('Base44 webhook error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'event' => $event,
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Base44 webhook exception: ' . $e->getMessage());
            return false;
        }
    }
}
