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

        // Gráficas de actividad MySQL (conexiones y consultas activas).
        // "Ahora" = la última muestra que sí trajo datos de MySQL (la más
        // reciente puede venir sin BD si esa lectura puntual falló).
        $hasMysql    = $samples->contains(fn ($s) => $s->mysql_conns !== null);
        $mysqlNow    = $hasMysql ? $samples->last(fn ($s) => $s->mysql_conns !== null) : null;
        $mysqlCharts = $hasMysql ? $this->buildMysqlCharts($samples) : [];

        return view('dashboard.trends', compact(
            'server', 'samples', 'hours', 'peak', 'stats', 'events', 'threshold',
            'hasMysql', 'mysqlNow', 'mysqlCharts'
        ));
    }

    /**
     * Construye los polígonos SVG de las gráficas de MySQL (escala por su máximo).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildMysqlCharts(\Illuminate\Support\Collection $samples): array
    {
        $W = 1000; $padL = 4; $padR = 4; $CH = 120; $cPadT = 8; $cPadB = 6;
        $iW = $W - $padL - $padR; $iH = $CH - $cPadT - $cPadB;
        $n = $samples->count();

        $defs = [
            ['key' => 'mysql_conns', 'label' => 'Conexiones a la base de datos', 'color' => '#a78bfa', 'max' => max(1, (int) $samples->max('mysql_conns'))],
            ['key' => 'mysql_running', 'label' => 'Consultas ejecutándose', 'color' => '#f472b6', 'max' => max(1, (int) $samples->max('mysql_running'))],
        ];

        $out = [];
        foreach ($defs as $def) {
            $pts = []; $points = []; $i = 0;
            foreach ($samples as $s) {
                $v = (int) ($s->{$def['key']} ?? 0);
                $x = $padL + ($n <= 1 ? $iW / 2 : $iW * $i / ($n - 1));
                $y = $cPadT + $iH * (1 - min(1, $v / $def['max']));
                $pts[] = round($x, 1).','.round($y, 1);
                $points[] = [round($x, 1), round($y, 1), $s->sampled_at->format('d/m H:i').' · '.$v];
                $i++;
            }
            $line = implode(' ', $pts);
            $f = explode(',', $pts[0]); $l = explode(',', $pts[count($pts) - 1]); $b = $cPadT + $iH;
            $def['line'] = $line;
            $def['area'] = $f[0].','.$b.' '.$line.' '.$l[0].','.$b;
            $def['points'] = $points;
            $out[] = $def;
        }

        return $out;
    }

    /** El análisis ahora vive dentro del panel unificado (tendencias). */
    public function analytics(Server $server)
    {
        return redirect(route('dashboard.servers.trends', $server).'#analisis');
    }

    /**
     * Fragmento HTML del reporte analítico (CPU, RAM, ancho de banda y MySQL).
     * Se carga por AJAX dentro del panel unificado para no frenar la página.
     */
    public function analyticsPanel(Server $server)
    {
        $data = ['server' => $server, 'report' => null, 'error' => null];

        if (! $server->hasCredentials()) {
            $data['error'] = 'Este servidor aún no tiene credenciales configuradas.';
        } else {
            try {
                $data['report'] = $this->monitor->analytics($server);
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('dashboard.partials.analytics-report', $data);
    }

    /** Optimizador de consultas MySQL con contexto para IA (página). */
    public function queryOptimizer(Server $server)
    {
        return view('dashboard.queries', compact('server'));
    }

    /** Fragmento HTML del optimizador de consultas (se carga por AJAX). */
    public function queryOptimizerPanel(Server $server, \App\Services\QueryAdvisor $advisor)
    {
        $data = ['server' => $server, 'report' => null, 'queries' => [], 'error' => null];

        if (! $server->hasCredentials()) {
            $data['error'] = 'Este servidor aún no tiene credenciales configuradas.';
        } else {
            try {
                $report = $this->monitor->queryReport($server);
                $data['report'] = $report;
                if ($report['available']) {
                    $data['queries'] = $advisor->analyze($report, $server);
                }
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('dashboard.partials.query-report', $data);
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
