<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\SshClient;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function __construct(private SshClient $ssh) {}

    public function create()
    {
        return view('dashboard.server-form', ['server' => new Server(['port' => 22, 'username' => 'root'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $server = Server::create($this->prepare($data));

        return redirect()->route('dashboard.servers.show', $server)
            ->with('status', 'Servidor agregado.');
    }

    public function edit(Server $server)
    {
        return view('dashboard.server-form', compact('server'));
    }

    public function update(Request $request, Server $server)
    {
        $data = $this->validated($request);
        $server->update($this->prepare($data, $server));

        return redirect()->route('dashboard.servers.show', $server)
            ->with('status', 'Servidor actualizado.');
    }

    public function destroy(Server $server)
    {
        $server->delete();

        return redirect()->route('dashboard.index')->with('status', 'Servidor eliminado.');
    }

    /** Prueba de conexión SSH (AJAX). */
    public function test(Server $server)
    {
        return response()->json($this->ssh->test($server));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'           => 'required|string|max:120',
            'provider'       => ['required', Rule::in(['contabo', 'winhosting', 'otro'])],
            'host'           => 'required|string|max:255',
            'port'           => 'required|integer|min:1|max:65535',
            'username'       => 'required|string|max:120',
            'auth_type'      => ['required', Rule::in(['password', 'key'])],
            'password'       => 'nullable|string',
            'private_key'    => 'nullable|string',
            'key_passphrase' => 'nullable|string',
            'color'          => 'nullable|string|max:9',
            'notes'          => 'nullable|string|max:2000',
            'is_active'      => 'nullable|boolean',
        ]);
    }

    /**
     * Prepara los datos: no sobreescribe secretos con vacío al editar.
     */
    private function prepare(array $data, ?Server $server = null): array
    {
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['color']     = $data['color'] ?: '#6366f1';

        foreach (['password', 'private_key', 'key_passphrase'] as $secret) {
            if (($data[$secret] ?? '') === '' || $data[$secret] === null) {
                // Al crear: null. Al editar sin cambiar: conservar el existente.
                if ($server) {
                    unset($data[$secret]);
                } else {
                    $data[$secret] = null;
                }
            }
        }

        return $data;
    }
}
