<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida la cabecera X-Twilio-Signature de los webhooks entrantes de Twilio.
 *
 * Algoritmo (https://www.twilio.com/docs/usage/webhooks/webhooks-security):
 *   1. Se toma la URL completa del webhook.
 *   2. Se ordenan los parámetros POST por clave y se concatenan clave+valor.
 *   3. Se calcula HMAC-SHA1 con el Auth Token y se codifica en base64.
 *   4. Se compara con la cabecera recibida.
 */
class TwilioSignatureMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Permite desactivar la validación (p. ej. sandbox o pruebas locales).
        if (!config('services.twilio.validate_signature', true)) {
            return $next($request);
        }

        $authToken = (string) config('services.twilio.auth_token');
        $signature = $request->header('X-Twilio-Signature');

        if ($authToken === '' || !$signature) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        if (!$this->isValid($request, $authToken, $signature)) {
            return response()->json(['error' => 'Invalid Twilio signature'], 403);
        }

        return $next($request);
    }

    private function isValid(Request $request, string $authToken, string $signature): bool
    {
        $data = $request->fullUrl();

        $params = $request->post();
        ksort($params);

        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signature);
    }
}
