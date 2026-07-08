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

        // Última muestra por servidor → las tarjetas pintan al instante (sin
        // esperar SSH). Luego el JavaScript refresca en vivo.
        $latest = \App\Models\MetricSample::whereIn('server_id', $servers->pluck('id'))
            ->orderByDesc('sampled_at')
            ->get()
            ->groupBy('server_id')
            ->map->first();

        // El botón de sincronizar Contabo solo aparece en el head cuando hace
        // falta: credenciales cargadas pero datos nunca traídos o desactualizados
        // (>12 h). Si todo está al día, el head queda limpio; la conexión vive
        // en el módulo de Configuración.
        $contaboConfigured = app(\App\Services\ContaboService::class)->configured();
        $contaboLastSync   = $servers->max('provider_synced_at');
        $contaboNeedsSync  = $contaboConfigured
            && ($contaboLastSync === null || \Illuminate\Support\Carbon::parse($contaboLastSync)->lt(now()->subHours(12)));

        return view('dashboard.index', compact('servers', 'latest', 'contaboConfigured', 'contaboNeedsSync'));
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

        $data['domainMeta'] = [];
        $data['byProject']  = [];

        if (! $data['needsCredentials']) {
            try {
                $data['metrics']   = $this->monitor->metrics($server);
                $data['projects']  = $this->monitor->projects($server);
                $map               = $this->monitor->siteMap($server);
                $data['domains']   = array_keys($map['domains']);
                $data['domainMeta'] = $map['domains'];
                $data['byProject']  = $map['byProject'];
                $data['processes'] = $this->monitor->topProcesses($server);
                $data['slow']      = $this->monitor->slowQueries($server);

                // Adjuntar a cada proyecto los dominios que le pertenecen
                foreach ($data['projects'] as &$p) {
                    $p['domains'] = $map['byProject'][$p['name']] ?? [];
                }
                unset($p);

                // Icono por origen para pintar cada dominio (se computa aquí
                // para que la vista no lleve lógica).
                $srcIcon = ['nginx' => '🟢', 'apache' => '🟢', 'cert' => '🔒', 'docker' => '🐳'];
                foreach ($data['domainMeta'] as $name => &$info) {
                    $info['icons'] = collect($info['sources'])
                        ->map(fn ($s) => $srcIcon[$s] ?? '•')->unique()->implode('');
                }
                unset($info);
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        // Agrupar los dominios por su raíz para una vista organizada
        $data['domainGroups'] = $this->groupDomains($data['domains']);

        return view('dashboard.show', array_merge(['server' => $server], $data));
    }

    /**
     * Panel «Usuarios en vivo»: quién está conectado ahora al servidor (IPs con
     * actividad web en el último minuto), de dónde vienen (país/ciudad) y su ISP.
     */
    public function liveVisitors(Server $server, \App\Services\GeoLocator $geo)
    {
        $base = ['server' => $server, 'needsCredentials' => ! $server->hasCredentials(),
                 'error' => null, 'visitors' => [], 'countries' => [], 'total' => 0,
                 'connections' => 0, 'localCount' => 0, 'diag' => null];

        if ($base['needsCredentials']) {
            return view('dashboard.live', $base);
        }

        try {
            $result = $this->monitor->liveVisitors($server);
        } catch (\Throwable $e) {
            return view('dashboard.live', array_merge($base, ['error' => $e->getMessage()]));
        }

        $conns = $result['visitors'];
        $base['diag'] = $result['diag'];

        $geos = $geo->locate(array_column($conns, 'ip'));

        $visitors = [];
        foreach ($conns as $c) {
            $g = $geos[$c['ip']] ?? null;
            $public = $geo->isPublic($c['ip']);
            $visitors[] = [
                'ip'      => $c['ip'],
                'conns'   => $c['conns'],
                'public'  => $public,
                'country' => $g['country'] ?? null,
                'cc'      => $g['countryCode'] ?? null,
                'flag'    => $public ? $geo->flag($g['countryCode'] ?? null) : '🏠',
                'city'    => $g['city'] ?? null,
                'isp'     => $g['isp'] ?? null,
                'lat'     => $g['lat'] ?? null,
                'lon'     => $g['lon'] ?? null,
            ];
        }

        // Resumen por país (solo visitantes públicos = usuarios reales)
        $countries = [];
        foreach ($visitors as $v) {
            if (! $v['public']) {
                continue;
            }
            $key = $v['country'] ?? 'Desconocido';
            $countries[$key] ??= ['country' => $key, 'flag' => $v['flag'], 'users' => 0];
            $countries[$key]['users']++;
        }
        usort($countries, fn ($a, $b) => $b['users'] <=> $a['users']);

        $publicVisitors = array_values(array_filter($visitors, fn ($v) => $v['public']));

        return view('dashboard.live', array_merge($base, [
            'visitors'    => $publicVisitors,
            'countries'   => $countries,
            'total'       => count($publicVisitors),                    // usuarios activos (IPs únicas)
            'connections' => array_sum(array_column($visitors, 'conns')), // conexiones totales
            'localCount'  => count($visitors) - count($publicVisitors), // internas (proxy/docker)
        ]));
    }

    /**
     * Agrupa una lista plana de dominios/subdominios por su dominio raíz.
     *
     * @param  array<int, string>  $domains
     * @return array<string, array<int, string>>
     */
    private function groupDomains(array $domains): array
    {
        $inspector = app(\App\Services\DomainInspector::class);
        $groups = [];

        foreach ($domains as $d) {
            $apex = $inspector->apexFor($d) ?: $d;
            $groups[$apex][] = $d;
        }

        // Ordenar: raíces alfabéticamente, y dentro cada grupo (la raíz primero)
        ksort($groups);
        foreach ($groups as $apex => &$list) {
            $list = array_values(array_unique($list));
            usort($list, function ($a, $b) use ($apex) {
                if ($a === $apex) return -1;
                if ($b === $apex) return 1;
                return strcmp($a, $b);
            });
        }

        return $groups;
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

        $peaksAi = $this->buildPeaksAiPrompt($server, $events, $stats, $hours, $threshold);

        return view('dashboard.trends', compact(
            'server', 'samples', 'hours', 'peak', 'stats', 'events', 'threshold',
            'hasMysql', 'mysqlNow', 'mysqlCharts', 'peaksAi'
        ));
    }

    /** Informe de picos en texto plano, listo para pegárselo a una IA. */
    private function buildPeaksAiPrompt(Server $server, array $events, array $stats, int $hours, int $threshold): string
    {
        $lines = [
            'Actúa como un ingeniero SRE experto en Linux, Docker y MySQL.',
            'Analiza este historial de picos de CPU de un servidor de producción y dame un plan concreto para reducirlos.',
            '',
            '## Servidor',
            '- '.$server->name.' ('.$server->host.')',
            '- Rango analizado: últimas '.$hours.' horas · umbral de pico: CPU ≥ '.$threshold.'%',
            '- CPU promedio del rango: '.($stats['avg'] ?? '?').'% · máximo: '.($stats['max'] ?? '?').'% · muestras: '.$stats['count'],
            '',
            '## Eventos de pico (los más recientes primero)',
        ];

        if (empty($events)) {
            $lines[] = '(sin picos en el rango)';
        }
        foreach (array_slice($events, 0, 20) as $ev) {
            $p = $ev['peak'];
            $l = '- '.$ev['start']->format('d/m H:i').': CPU '.$p->cpu_pct.'% · RAM '.($p->memPct() ?? '?').'% · carga '.$p->load1
                .' · proceso: '.($p->top_cpu_cmd ?? '—').($p->top_cpu_pct ? ' ('.$p->top_cpu_pct.'%)' : '');
            if ($p->top_container) {
                $l .= ' · contenedor Docker: '.$p->top_container.' ('.$p->top_container_pct.'% de un núcleo)';
            }
            $lines[] = $l;
        }

        $lines[] = '';
        $lines[] = '## Qué necesito de ti';
        $lines[] = '1. Qué patrón ves en los picos (¿horarios? ¿mismo contenedor/proceso?).';
        $lines[] = '2. Causas más probables y cómo confirmarlas (comandos exactos que puedo ejecutar).';
        $lines[] = '3. Acciones concretas para reducirlos (límites de CPU por contenedor, optimización de consultas, mover tareas pesadas de horario, etc.).';
        $lines[] = '4. Qué umbrales de alerta me recomiendas para este servidor.';
        $lines[] = 'Si te falta información, dime exactamente qué comando ejecutar y te pego el resultado.';

        return implode("\n", $lines);
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
        $data = ['server' => $server, 'report' => null, 'queries' => [], 'resolved' => [], 'error' => null];

        if (! $server->hasCredentials()) {
            $data['error'] = 'Este servidor aún no tiene credenciales configuradas.';
        } else {
            try {
                $report = $this->monitor->queryReport($server);
                $data['report'] = $report;
                if ($report['available']) {
                    $queries = $advisor->analyze($report, $server);
                    // El seguimiento es un extra: si su tabla aún no existe
                    // (migración sin correr) o falla, mostramos las consultas
                    // igual, sin tendencia, en vez de romper la página.
                    try {
                        [$queries, $data['resolved']] = $this->trackQueries($server, $queries);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Seguimiento de consultas no disponible', ['error' => $e->getMessage()]);
                    }
                    $data['queries'] = $queries;
                }
            } catch (\Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return view('dashboard.partials.query-report', $data);
    }

    /**
     * Seguimiento de consultas: compara cada consulta con su última foto
     * guardada para saber si mejoró/empeoró tras optimizarla, detecta las que
     * ya salieron del ranking (posiblemente resueltas) y guarda una nueva foto
     * (como máximo una cada 15 min por servidor, para no llenar la tabla).
     *
     * @return array{0: array<int,array>, 1: array<int,array>} [consultas con tendencia, resueltas]
     */
    private function trackQueries(Server $server, array $queries): array
    {
        $prior = \App\Models\QuerySnapshot::latestPerDigest($server->id);
        $currentDigests = [];

        foreach ($queries as &$q) {
            $digest = $q['digest'] ?? '';
            $currentDigests[$digest] = true;
            $q['trend'] = $this->compareTrend($q, $prior[$digest] ?? null);
        }
        unset($q);

        // Resueltas: estaban en una foto de los últimos 7 días y ya no aparecen
        $resolved = [];
        foreach ($prior as $digest => $snap) {
            if (isset($currentDigests[$digest]) || $snap->seen_at->lt(now()->subDays(7))) {
                continue;
            }
            $resolved[] = [
                'db'         => $snap->db,
                'query'      => $snap->query_preview,
                'total_s'    => $snap->total_s,
                'execs'      => $snap->execs,
                'avg_ms'     => $snap->avg_ms,
                'last_seen'  => $snap->seen_at,
            ];
        }

        // Guardar nueva foto (throttle: una cada 15 min por servidor)
        if (\Illuminate\Support\Facades\Cache::add("qsnap:{$server->id}", true, 900)) {
            foreach ($queries as $q) {
                \App\Models\QuerySnapshot::create([
                    'server_id'     => $server->id,
                    'digest'        => $q['digest'] ?? '',
                    'db'            => $q['db'] ?? null,
                    'query_preview' => mb_substr($q['query'] ?? '', 0, 500),
                    'total_s'       => $q['total_s'],
                    'execs'         => $q['execs'],
                    'avg_ms'        => $q['avg_ms'],
                    'rows_ratio'    => $q['ratio'],
                    'seen_at'       => now(),
                ]);
            }
            // Poda: conservar ~60 días de histórico
            \App\Models\QuerySnapshot::where('server_id', $server->id)
                ->where('seen_at', '<', now()->subDays(60))->delete();
        }

        return [$queries, $resolved];
    }

    /**
     * Compara una consulta con su foto anterior. Usa el promedio (avg_ms) como
     * termómetro de "salud" (los índices bajan el promedio) exigiendo un cambio
     * ≥15% para no marcar ruido.
     *
     * @return array{state: string, label: string, delta_pct: ?int, since: ?string}
     */
    private function compareTrend(array $q, ?\App\Models\QuerySnapshot $prev): array
    {
        if (! $prev || $prev->seen_at->gt(now()->subMinutes(10))) {
            // Sin referencia útil todavía (nueva, o la foto previa es de hace un rato)
            return ['state' => $prev ? 'estable' : 'nueva',
                'label' => $prev ? '● en seguimiento' : '✨ nueva en el ranking',
                'delta_pct' => null, 'since' => $prev?->seen_at?->diffForHumans()];
        }

        $before = max(0.01, (float) $prev->avg_ms);
        $now    = (float) $q['avg_ms'];
        $pct    = (int) round(($now - $before) / $before * 100);

        if ($pct <= -15) {
            return ['state' => 'mejorando', 'label' => '✅ mejoró '.abs($pct).'%', 'delta_pct' => $pct, 'since' => $prev->seen_at->diffForHumans()];
        }
        if ($pct >= 15) {
            return ['state' => 'empeorando', 'label' => '⚠️ empeoró '.$pct.'%', 'delta_pct' => $pct, 'since' => $prev->seen_at->diffForHumans()];
        }

        return ['state' => 'estable', 'label' => '● estable', 'delta_pct' => $pct, 'since' => $prev->seen_at->diffForHumans()];
    }

    /** Actividad de MySQL en vivo (JSON): alimenta el refresco automático. */
    public function mysqlLive(Server $server)
    {
        if (! $server->hasCredentials()) {
            return response()->json(['ok' => false, 'needs_credentials' => true], 200);
        }

        try {
            return response()->json(['ok' => true] + $this->monitor->mysqlLive($server));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /** Endpoint JSON para refrescar solo las métricas (auto-refresh). */
    public function metrics(Server $server)
    {
        if (! $server->hasCredentials()) {
            return response()->json(['ok' => false, 'needs_credentials' => true], 200);
        }

        try {
            $m        = $this->monitor->metrics($server);
            $critical = (int) config('dashboard.cpu_critical_threshold', 90);
            $peak     = null;

            if (($m['cpu_pct'] ?? null) !== null && $m['cpu_pct'] >= $critical) {
                $peak = $this->capturePeakNow($server, $m);
            }

            return response()->json([
                'ok'       => true,
                'metrics'  => $m,
                'critical' => $critical,
                'peak'     => $peak,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * Registra AL INSTANTE un pico crítico visto en vivo (sin esperar el
     * muestreo de 5 min): guarda la muestra con el proceso culpable y dispara
     * las alertas. Máximo una captura por minuto por servidor.
     *
     * @return array{captured: bool, process: ?string, pct: ?float}|null
     */
    private function capturePeakNow(Server $server, array $m): ?array
    {
        if (! \Illuminate\Support\Facades\Cache::add("peak-capture:{$server->id}", true, 60)) {
            return null; // ya se registró hace menos de un minuto
        }

        try {
            // Foto instantánea (sin caché): el consumo REAL del momento,
            // incluyendo qué contenedor Docker es el responsable.
            $top = ['cpu' => [], 'mem' => []];
            $containers = [];
            try {
                $snap = $this->monitor->peakSnapshot($server);
                $top = $snap['top'];
                $containers = $snap['containers'];
            } catch (\Throwable) {
            }

            $sample = \App\Models\MetricSample::fromMetrics($server, $m, $top, containers: $containers);
            app(\App\Services\AlertService::class)->checkSample($server, $sample);

            return [
                'captured'      => true,
                'process'       => $sample->top_cpu_cmd,
                'pct'           => $sample->top_cpu_pct,
                'container'     => $sample->top_container,
                'container_pct' => $sample->top_container_pct,
            ];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('No se pudo registrar el pico crítico', [
                'server' => $server->name, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
