<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ServerMonitor;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private ServerMonitor $monitor) {}

    /** Vista de login del dashboard. */
    public function loginForm()
    {
        if (! config('dashboard.password') || session('dashboard_authed')) {
            return redirect()->route('dashboard.index');
        }

        return view('dashboard.login');
    }

    /** Intento de login. */
    public function login(Request $request)
    {
        $request->validate(['password' => 'required|string']);

        if (hash_equals((string) config('dashboard.password'), $request->input('password'))) {
            $request->session()->regenerate();
            $request->session()->put('dashboard_authed', true);

            return redirect()->intended(route('dashboard.index'));
        }

        return back()->withErrors(['password' => 'Contraseña incorrecta.']);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('dashboard_authed');

        return redirect()->route('dashboard.login');
    }

    /** Overview: todos los servidores. */
    public function index()
    {
        $servers = Server::orderBy('provider')->orderBy('name')->get();

        return view('dashboard.index', compact('servers'));
    }

    /** Detalle de un servidor: métricas + proyectos + dominios. */
    public function show(Server $server)
    {
        $data = [
            'metrics'          => null,
            'projects'         => [],
            'domains'          => [],
            'error'            => null,
            'needsCredentials' => ! $server->hasCredentials(),
        ];

        $data['processes'] = ['cpu' => [], 'mem' => []];
        $data['slow']      = ['enabled' => false, 'file' => null, 'top' => []];

        if (! $data['needsCredentials']) {
            try {
                $data['metrics']   = $this->monitor->metrics($server);
                $data['projects']  = $this->monitor->projects($server);
                $data['domains']   = $this->monitor->domains($server);
                $data['processes'] = $this->monitor->topProcesses($server);
                $data['slow']      = $this->monitor->slowQueries($server);
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('dashboard.show', array_merge(['server' => $server], $data));
    }

    /** Histórico de métricas: gráficas de tendencia (Fase 2). */
    public function trends(Request $request, Server $server)
    {
        $hours = (int) $request->query('h', 24);
        $hours = in_array($hours, [6, 24, 72, 168], true) ? $hours : 24;

        $samples = \App\Models\MetricSample::where('server_id', $server->id)
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get();

        // Pico de CPU del rango (para señalar el culpable)
        $peak = $samples->sortByDesc('cpu_pct')->first();

        return view('dashboard.trends', compact('server', 'samples', 'hours', 'peak'));
    }

    /** Reporte analítico integral: CPU, RAM, ancho de banda y MySQL. */
    public function analytics(Server $server)
    {
        $data = ['server' => $server, 'report' => null, 'error' => null];

        if (! $server->hasCredentials()) {
            $data['error'] = 'Este servidor aún no tiene credenciales configuradas.';

            return view('dashboard.analytics', $data);
        }

        try {
            $data['report'] = $this->monitor->analytics($server);
        } catch (\Throwable $e) {
            $data['error'] = $e->getMessage();
        }

        return view('dashboard.analytics', $data);
    }

    /** Endpoint JSON para refrescar solo las métricas (auto-refresh). */
    public function metrics(Server $server)
    {
        if (! $server->hasCredentials()) {
            return response()->json(['ok' => false, 'needs_credentials' => true], 200);
        }

        try {
            return response()->json(['ok' => true, 'metrics' => $this->monitor->metrics($server)]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }
}
