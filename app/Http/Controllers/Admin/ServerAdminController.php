<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\Ssh\ServerRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ServerAdminController extends Controller
{
    public function __construct(private ServerRegistry $registry) {}

    /**
     * Panel principal: lista de todos los servidores.
     */
    public function dashboard()
    {
        $servers = $this->registry->all();

        return view('admin.dashboard', [
            'servers' => $servers,
            'mode' => config('servers.mode'),
        ]);
    }

    /**
     * Detalle de un servidor: métricas de rendimiento.
     */
    public function show(string $key)
    {
        $server = $this->registry->find($key);
        abort_if(! $server, 404, 'Servidor no encontrado');

        $overview = null;
        $error = null;

        try {
            $monitor = $this->registry->monitor($key);
            $overview = $monitor->overview();

            // Marca de última revisión si es un servidor de base de datos.
            Server::where('key', $key)->update(['last_checked_at' => now()]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.server', [
            'server' => $server,
            'overview' => $overview,
            'error' => $error,
        ]);
    }

    /**
     * Explorador de carpetas del servidor.
     */
    public function folders(string $key, Request $request)
    {
        $server = $this->registry->find($key);
        abort_if(! $server, 404, 'Servidor no encontrado');

        $path = $request->query('path', $server['base_path']);
        $result = ['path' => $path, 'error' => null, 'entries' => []];
        $error = null;

        try {
            $monitor = $this->registry->monitor($key);
            $result = $monitor->listPath($path);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Breadcrumb de rutas
        $segments = collect(explode('/', trim($path, '/')))->filter();
        $crumbs = [];
        $acc = '';
        foreach ($segments as $seg) {
            $acc .= '/'.$seg;
            $crumbs[] = ['name' => $seg, 'path' => $acc];
        }

        return view('admin.folders', [
            'server' => $server,
            'result' => $result,
            'error' => $error,
            'crumbs' => $crumbs,
            'parent' => $this->parentPath($path),
        ]);
    }

    /**
     * Prueba de conexión (AJAX). Devuelve JSON.
     */
    public function test(string $key)
    {
        try {
            $monitor = $this->registry->monitor($key);
            abort_if(! $monitor, 404);

            $ping = $monitor->ping();

            return response()->json(['ok' => true] + $ping);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    // ── CRUD de servidores (almacenados en base de datos) ────────────────────

    public function create()
    {
        return view('admin.server-form', ['server' => new Server(['port' => 22, 'auth_method' => 'key', 'group' => 'vps'])]);
    }

    public function store(Request $request)
    {
        $data = $this->validateServer($request);
        $data['key'] = ($data['key'] ?? null) ?: Str::slug($data['name']);

        // Garantiza unicidad de la 'key'.
        $base = $data['key'];
        $i = 2;
        while (Server::where('key', $data['key'])->exists()) {
            $data['key'] = $base.'-'.$i++;
        }

        Server::create($data);

        return redirect()->route('admin.dashboard')->with('status', 'Servidor añadido.');
    }

    public function edit(Server $server)
    {
        return view('admin.server-form', ['server' => $server]);
    }

    public function update(Request $request, Server $server)
    {
        $data = $this->validateServer($request, $server->id);

        // No sobreescribir la contraseña si el campo llega vacío.
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $server->update($data);

        return redirect()->route('admin.dashboard')->with('status', 'Servidor actualizado.');
    }

    public function destroy(Server $server)
    {
        $server->delete();

        return redirect()->route('admin.dashboard')->with('status', 'Servidor eliminado.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateServer(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'key' => 'nullable|string|max:100',
            'name' => 'required|string|max:150',
            'group' => 'required|string|max:50',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:100',
            'auth_method' => 'required|in:key,password',
            'private_key_path' => 'nullable|string|max:500',
            'password' => 'nullable|string|max:500',
            'base_path' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function parentPath(string $path): ?string
    {
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/') {
            return null;
        }
        $parent = dirname($path);

        return $parent ?: '/';
    }
}
