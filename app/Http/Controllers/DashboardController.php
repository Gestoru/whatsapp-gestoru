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

    /** Activa el slow query log de MySQL en todos los servidores activos. */
    public function enableSlowLogAll()
    {
        $servers = Server::where('is_active', true)->get()
            ->filter(fn (Server $s) => $s->hasCredentials());

        $lines = [];
        foreach ($servers as $server) {
            try {
                $r = $this->monitor->enableSlowQueryLog($server);
                $lines[] = ($r['ok'] ? '✅ ' : '⚠️ ').$server->name.': '.$r['message'];
            } catch (\Throwable $e) {
                $lines[] = '❌ '.$server->name.': '.$e->getMessage();
            }
        }

        $msg = $lines ? implode(' · ', $lines) : 'No hay servidores con credenciales.';

        return redirect()->back()->with('status', $msg);
    }

    /** Histórico de métricas: gráficas de tendencia en tiempo real (Fase 2). */
    public function trends(Request $request, Server $server)
    {
        $hours = (int) $request->query('h', 24);
        $hours = in_array($hours, [6, 24, 72, 168], true) ? $hours : 24;

        $samples = \App\Models\MetricSample::where('server_id', $server->id)
            ->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')
            ->get();

        $peak = $samples->sortByDesc('cpu_pct')->first();

        // Estadísticas del rango
        $cpu = $samples->pluck('cpu_pct')->filter(fn ($v) => $v !== null);
        $stats = [
            'avg'   => $cpu->isNotEmpty() ? (int) round($cpu->avg()) : null,
            'max'   => $cpu->max(),
            'min'   => $cpu->min(),
            'count' => $samples->count(),
        ];

        // Detección de EVENTOS DE PICO: tramos contiguos con CPU alta
        $threshold = (int) config('dashboard.cpu_peak_threshold', 50);
        $events = [];
        $run = null;
        foreach ($samples as $s) {
            if ($s->cpu_pct !== null && $s->cpu_pct >= $threshold) {
                if (! $run) {
                    $run = ['start' => $s->sampled_at, 'end' => $s->sampled_at, 'peak' => $s, 'n' => 1];
                } else {
                    $run['end'] = $s->sampled_at;
                    $run['n']++;
                    if ($s->cpu_pct > $run['peak']->cpu_pct) {
                        $run['peak'] = $s;
                    }
                }
            } elseif ($run) {
                $events[] = $run;
                $run = null;
            }
        }
        if ($run) {
            $events[] = $run;
        }
        $events = array_reverse($events); // más recientes primero

        return view('dashboard.trends', compact('server', 'samples', 'hours', 'peak', 'stats', 'events', 'threshold'));
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
