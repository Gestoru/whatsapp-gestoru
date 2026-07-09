<?php

namespace App\Services;

use Anthropic\Lib\Credentials\CredentialResult;
use Anthropic\Lib\Credentials\TokenCache;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Conexión con la «Cuenta de Claude» (suscripción Pro, Max, Team o Enterprise)
 * mediante OAuth, el mismo mecanismo que usa la CLI oficial de Claude Code.
 *
 * Flujo (con PKCE y código manual, sin necesidad de montar un callback):
 *   1. authorizeUrl(): genera el verificador/estado y la URL de autorización.
 *   2. El usuario autoriza en su cuenta y copia el código que le muestra.
 *   3. exchange($code): canjea el código por access_token + refresh_token.
 *   4. accessToken(): entrega un token válido, renovándolo si está por vencer.
 *
 * Los tokens se guardan cifrados en «settings». El header beta de OAuth y el
 * Bearer los pone el propio SDK de Anthropic.
 */
class ClaudeOAuth
{
    // Cliente OAuth público de Claude Code (el mismo de la CLI oficial).
    private const CLIENT_ID    = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';
    private const AUTHORIZE_URL = 'https://claude.ai/oauth/authorize';
    private const TOKEN_URL     = 'https://console.anthropic.com/v1/oauth/token';
    private const REDIRECT_URI  = 'https://console.anthropic.com/oauth/code/callback';
    private const SCOPES        = 'org:create_api_key user:profile user:inference';

    private const KEY     = 'anthropic_oauth';          // tokens (cifrado)
    private const PENDING = 'anthropic_oauth_pending';  // verificador/estado en curso (cifrado)

    // Margen para renovar el token antes de que venza (segundos).
    private const REFRESH_BUFFER = 120;

    public function connected(): bool
    {
        return $this->stored() !== null;
    }

    /** Etiqueta de la cuenta conectada, para mostrarla en el panel. */
    public function account(): ?string
    {
        return $this->stored()['account'] ?? null;
    }

    /** Credencial lista para el SDK de Anthropic (con renovación automática). */
    public function credential(): CredentialResult
    {
        return new CredentialResult(
            provider: new TokenCache(new ClaudeTokenProvider($this)),
        );
    }

    // ── Paso 1: URL de autorización ──────────────────────────────────────────

    /** Genera la URL a la que el usuario debe ir para autorizar su cuenta. */
    public function authorizeUrl(): string
    {
        $verifier  = $this->base64Url(random_bytes(32));
        $challenge = $this->base64Url(hash('sha256', $verifier, true));
        $state     = $this->base64Url(random_bytes(24));

        Setting::put(self::PENDING, Crypt::encryptString(json_encode([
            'verifier' => $verifier,
            'state'    => $state,
        ])));

        return self::AUTHORIZE_URL.'?'.http_build_query([
            'code'                  => 'true',
            'client_id'             => self::CLIENT_ID,
            'response_type'         => 'code',
            'redirect_uri'          => self::REDIRECT_URI,
            'scope'                 => self::SCOPES,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
        ]);
    }

    // ── Paso 2: canjear el código por tokens ─────────────────────────────────

    /**
     * Canjea el código (formato «codigo#estado» que copia el usuario) por los
     * tokens de acceso. Lanza excepción con un mensaje claro si algo falla.
     */
    public function exchange(string $input): void
    {
        $pending = $this->pending();
        if ($pending === null) {
            throw new \RuntimeException('La sesión de conexión expiró. Vuelve a generar el enlace e inténtalo de nuevo.');
        }

        [$code, $state] = array_pad(explode('#', trim($input), 2), 2, null);
        $code  = trim((string) $code);
        $state = $state !== null ? trim($state) : $pending['state'];

        if ($code === '') {
            throw new \RuntimeException('El código está vacío. Copia el código completo que te mostró Claude.');
        }
        if ($state !== $pending['state']) {
            throw new \RuntimeException('El estado no coincide (posible enlace viejo). Genera un enlace nuevo e inténtalo otra vez.');
        }

        $r = Http::asJson()->acceptJson()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::CLIENT_ID,
            'code'          => $code,
            'state'         => $state,
            'redirect_uri'  => self::REDIRECT_URI,
            'code_verifier' => $pending['verifier'],
        ]);

        if (! $r->successful()) {
            throw new \RuntimeException('Claude rechazó la conexión ('.$r->status().'): '.($r->json('error_description') ?? $r->json('error') ?? 'error desconocido'));
        }

        $this->save($r->json());
        Setting::put(self::PENDING, '');   // limpia el estado en curso
    }

    // ── Paso 3: token válido (renueva si hace falta) ─────────────────────────

    /**
     * Devuelve un access token válido, renovándolo con el refresh token si está
     * por vencer.
     *
     * @return array{token: string, expires_at: ?int}
     */
    public function accessToken(): array
    {
        $data = $this->stored();
        if ($data === null) {
            throw new \RuntimeException('No hay una cuenta de Claude conectada.');
        }

        $expiresAt = $data['expires_at'] ?? null;
        $vigente = $expiresAt === null || $expiresAt > time() + self::REFRESH_BUFFER;
        if ($vigente && ! empty($data['access_token'])) {
            return ['token' => $data['access_token'], 'expires_at' => $expiresAt];
        }

        return $this->refresh($data);
    }

    public function disconnect(): void
    {
        Setting::put(self::KEY, '');
        Setting::put(self::PENDING, '');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** @return array{token: string, expires_at: ?int} */
    private function refresh(array $data): array
    {
        if (empty($data['refresh_token'])) {
            throw new \RuntimeException('La sesión de Claude venció y no hay refresh token. Vuelve a conectar tu cuenta.');
        }

        $r = Http::asJson()->acceptJson()->timeout(30)->post(self::TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::CLIENT_ID,
            'refresh_token' => $data['refresh_token'],
        ]);

        if (! $r->successful()) {
            throw new \RuntimeException('No se pudo renovar la sesión de Claude ('.$r->status().'). Vuelve a conectar tu cuenta.');
        }

        $saved = $this->save($r->json(), $data);

        return ['token' => $saved['access_token'], 'expires_at' => $saved['expires_at'] ?? null];
    }

    /** Guarda los tokens cifrados y devuelve el arreglo guardado. */
    private function save(array $resp, array $previous = []): array
    {
        $expiresIn = $resp['expires_in'] ?? null;
        $data = [
            'access_token'  => $resp['access_token'] ?? ($previous['access_token'] ?? ''),
            'refresh_token' => $resp['refresh_token'] ?? ($previous['refresh_token'] ?? ''),
            'expires_at'    => is_numeric($expiresIn) ? time() + (int) $expiresIn : ($previous['expires_at'] ?? null),
            'account'       => $this->accountLabel($resp) ?? ($previous['account'] ?? null),
        ];

        Setting::put(self::KEY, Crypt::encryptString(json_encode($data)));

        return $data;
    }

    /** Intenta sacar una etiqueta legible (correo/organización) de la respuesta. */
    private function accountLabel(array $resp): ?string
    {
        return $resp['account']['email_address']
            ?? $resp['account']['email']
            ?? $resp['organization']['name']
            ?? null;
    }

    private function stored(): ?array
    {
        $raw = Setting::get(self::KEY);
        if (empty($raw)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;
        }

        return (is_array($data) && ! empty($data['access_token'])) ? $data : null;
    }

    private function pending(): ?array
    {
        $raw = Setting::get(self::PENDING);
        if (empty($raw)) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($raw), true);
        } catch (\Throwable) {
            return null;
        }

        return (is_array($data) && ! empty($data['verifier']) && ! empty($data['state'])) ? $data : null;
    }

    private function base64Url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
