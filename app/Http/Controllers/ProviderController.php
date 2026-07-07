<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ContaboService;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    /** Enciende / apaga / reinicia un VPS de Contabo por API. */
    public function serverAction(Server $server, string $action, ContaboService $contabo)
    {
        $r = $contabo->instanceAction((string) $server->provider_instance_id, $action);

        // Refrescar estado tras la acción
        if ($r['ok']) {
            \Illuminate\Support\Facades\Cache::forget('contabo_token');
        }

        return redirect()->route('dashboard.servers.show', $server)
            ->with($r['ok'] ? 'status' : 'error', $r['message']);
    }

    /** Guarda las credenciales de la API de Contabo. */
    public function connectContabo(Request $request)
    {
        $data = $request->validate([
            'contabo_client_id'     => 'required|string|max:200',
            'contabo_client_secret' => 'nullable|string|max:300',
            'contabo_api_user'      => 'required|string|max:200',
            'contabo_api_password'  => 'nullable|string|max:200',
        ]);

        ContaboService::saveCredentials(
            $data['contabo_client_id'],
            $data['contabo_client_secret'] ?? null,
            $data['contabo_api_user'],
            $data['contabo_api_password'] ?? null,
        );

        return redirect()->route('dashboard.index')->with('status', 'Credenciales de Contabo guardadas. Ahora presiona «Sincronizar Contabo».');
    }

    /** Trae estado/plan/renovación de todos los VPS de Contabo. */
    public function syncContabo(ContaboService $contabo)
    {
        $r = $contabo->syncToServers();

        if ($r['ok']) {
            return redirect()->route('dashboard.index')
                ->with('status', "✅ Contabo sincronizado: {$r['total']} VPS ({$r['created']} nuevos, {$r['matched']} actualizados).");
        }

        return redirect()->route('dashboard.index')->with('error', 'Contabo: '.$r['error']);
    }
}
