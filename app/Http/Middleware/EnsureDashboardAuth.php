<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el dashboard de infraestructura con una contraseña única
 * (DASHBOARD_PASSWORD). Evita exponer credenciales SSH sin autenticación.
 */
class EnsureDashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Si no se configuró contraseña, no bloquea (útil en local),
        // pero se recomienda encarecidamente definirla en producción.
        $password = config('dashboard.password');

        if (! $password || $request->session()->get('dashboard_authed') === true) {
            return $next($request);
        }

        return redirect()->route('dashboard.login');
    }
}
