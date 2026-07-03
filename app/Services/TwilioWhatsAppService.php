<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente para enviar y verificar mensajes de WhatsApp a través de la
 * API REST de Twilio (https://api.twilio.com/2010-04-01).
 *
 * Sigue el mismo contrato que VpsWhatsAppService para que ambos proveedores
 * sean intercambiables desde los controladores.
 */
class TwilioWhatsAppService
{
    private const BASE_URI = 'https://api.twilio.com/2010-04-01/';

    private Client $client;
    private string $accountSid;
    private string $authToken;
    private ?string $whatsappFrom;
    private ?string $smsFrom;

    public function __construct()
    {
        $this->accountSid   = (string) config('services.twilio.account_sid');
        $this->authToken    = (string) config('services.twilio.auth_token');
        $this->whatsappFrom = config('services.twilio.whatsapp_from');
        $this->smsFrom      = config('services.twilio.sms_from');

        $this->client = new Client([
            'base_uri' => self::BASE_URI,
            'timeout'  => 30,
        ]);
    }

    /** Indica si las credenciales de Twilio están configuradas. */
    public function isConfigured(): bool
    {
        return $this->accountSid !== '' && $this->authToken !== '';
    }

    /**
     * Verifica la conexión con la cuenta de Twilio consultando el recurso Account.
     * Devuelve los datos de la cuenta (friendly_name, status, ...).
     *
     * @throws RuntimeException  si faltan credenciales
     * @throws RequestException  si Twilio rechaza la petición (credenciales inválidas)
     */
    public function verifyConnection(): array
    {
        $this->assertConfigured();

        $response = $this->client->get("Accounts/{$this->accountSid}.json", [
            'auth' => [$this->accountSid, $this->authToken],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    /**
     * Envía un mensaje de WhatsApp a través de Twilio.
     *
     * @param string      $phone     Teléfono destino en E.164 (con o sin prefijo whatsapp:)
     * @param string      $content   Cuerpo del mensaje
     * @param string|null $mediaType text|image|audio|video|document
     * @param string|null $mediaUrl  URL pública del adjunto (requerida por Twilio para media)
     */
    public function sendMessage(
        string $phone,
        string $content,
        ?string $mediaType = null,
        ?string $mediaUrl = null,
        ?string $mediaName = null,
    ): array {
        $this->assertConfigured();

        if (!$this->whatsappFrom) {
            throw new RuntimeException('TWILIO_WHATSAPP_FROM no está configurado.');
        }

        $payload = [
            'From' => $this->normalizeWhatsApp($this->whatsappFrom),
            'To'   => $this->normalizeWhatsApp($phone),
            'Body' => $content,
        ];

        if ($mediaType && $mediaType !== 'text' && $mediaUrl) {
            // Twilio acepta varias MediaUrl; aquí enviamos una.
            $payload['MediaUrl'] = [$mediaUrl];
        }

        return $this->post("Accounts/{$this->accountSid}/Messages.json", $payload);
    }

    /**
     * Envía un SMS a través de Twilio.
     *
     * @param string      $phone Teléfono destino en E.164 (p. ej. +18095551234)
     * @param string      $body  Texto del mensaje
     * @param string|null $from  Remitente (nº E.164 o Messaging Service SID MG...).
     *                           Si es null usa TWILIO_SMS_FROM.
     */
    public function sendSms(string $phone, string $body, ?string $from = null): array
    {
        $this->assertConfigured();

        $from ??= $this->smsFrom;

        if (!$from) {
            throw new RuntimeException('TWILIO_SMS_FROM no está configurado.');
        }

        $payload = [
            'To'   => $phone,
            'Body' => $body,
        ];

        // Un Messaging Service SID empieza por "MG"; en ese caso se usa
        // MessagingServiceSid en lugar de From.
        if (str_starts_with($from, 'MG')) {
            $payload['MessagingServiceSid'] = $from;
        } else {
            $payload['From'] = $from;
        }

        return $this->post("Accounts/{$this->accountSid}/Messages.json", $payload);
    }

    /** Indica si el envío de SMS está configurado (credenciales + remitente). */
    public function isSmsConfigured(): bool
    {
        return $this->isConfigured() && !empty($this->smsFrom);
    }

    // ── Private ─────────────────────────────────────────────────────────────

    private function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, [
                'auth'        => [$this->accountSid, $this->authToken],
                'form_params' => $data,
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            $context = ['endpoint' => $endpoint];
            if ($e->hasResponse()) {
                $context['response'] = $e->getResponse()->getBody()->getContents();
                $context['status']   = $e->getResponse()->getStatusCode();
            }
            Log::error('Twilio WhatsApp error', array_merge($context, ['error' => $e->getMessage()]));
            throw $e;
        }
    }

    /** Antepone el prefijo whatsapp: exigido por Twilio si aún no está presente. */
    private function normalizeWhatsApp(string $number): string
    {
        $number = trim($number);

        return str_starts_with($number, 'whatsapp:')
            ? $number
            : 'whatsapp:' . $number;
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Faltan credenciales de Twilio. Define TWILIO_ACCOUNT_SID y TWILIO_AUTH_TOKEN en tu .env.'
            );
        }
    }
}
