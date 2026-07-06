<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el panel /admin con una contraseña simple guardada en .env
 * (SERVER_ADMIN_PASSWORD). El acceso se recuerda en la sesión.
 */
class ServerAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('server_admin_authed')) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
