<?php

namespace App\Services;

use Anthropic\Lib\Credentials\AccessToken;
use Anthropic\Lib\Credentials\AccessTokenProvider;

/**
 * Proveedor de token para el SDK de Anthropic cuando se usa la conexión por
 * «Cuenta de Claude» (suscripción, vía OAuth). Devuelve el access token
 * guardado y, si está por vencer, lo renueva con el refresh token. El SDK
 * envía el Bearer y el header beta de OAuth por su cuenta.
 */
class ClaudeTokenProvider implements AccessTokenProvider
{
    public function __construct(private ClaudeOAuth $oauth) {}

    public function fetchToken(): AccessToken
    {
        $tok = $this->oauth->accessToken();   // renueva si hace falta

        return new AccessToken($tok['token'], $tok['expires_at'] ?? null);
    }
}
