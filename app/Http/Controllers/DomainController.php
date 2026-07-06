<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ServerMonitor;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function __construct(private ServerMonitor $monitor) {}

    /** Reporte de observabilidad de un dominio (Fase 1: solo lectura). */
    public function show(Request $request, Server $server)
    {
        $request->validate(['d' => 'required|string|max:255']);
        $domain = $request->query('d');

        $data = [
            'server'     => $server,
            'domain'     => $domain,
            'site'       => null,
            'report'     => null,
            'error'      => null,
            'diagnostic' => null,
        ];

        if (! $server->hasCredentials()) {
            $data['error'] = 'Este servidor aún no tiene credenciales configuradas.';

            return view('dashboard.domain', $data);
        }

        try {
            $data['site'] = $this->monitor->siteFor($server, $domain);

            if ($data['site']) {
                $data['report'] = $this->monitor->domainReport($server, $data['site']);
            } else {
                $data['error']      = 'No encontré una configuración de nginx/Apache con logs para este dominio en el servidor.';
                $data['diagnostic'] = $this->monitor->domainDiagnostic($server, $domain);
            }
        } catch (\Throwable $e) {
            $data['error'] = $e->getMessage();
        }

        return view('dashboard.domain', $data);
    }
}
