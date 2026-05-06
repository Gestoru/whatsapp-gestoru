<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookSecretMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->header('x-webhook-secret');

        if (!$secret || $secret !== config('services.vps.webhook_secret')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
