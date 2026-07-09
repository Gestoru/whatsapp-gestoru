<?php

namespace App\Services;

use App\Models\MetricSample;
use App\Models\PerfIssue;
use App\Models\Server;
use Illuminate\Support\Carbon;

/**
 * Construye y mantiene el "Tablero de rendimiento": convierte los picos de CPU
 * y las consultas MySQL pesadas en incidencias con estado (por revisar → en
 * optimización → resuelta / no aplica), calcula una causa probable automática
 * y arma el contexto para pedirle a una IA el plan de solución.
 */
class PerfBoard
{
    public function __construct(
        private ServerMonitor $monitor,
        private QueryAdvisor $advisor,
    ) {}

    /**
     * Sincroniza las incidencias del servidor con la realidad actual y las
     * devuelve agrupadas por columna del tablero.
     *
     * @return array<string, array<int, PerfIssue>>
     */
    public function sync(Server $server): array
    {
        $seen = [];

        foreach ($this->detectCpuPeaks($server) as $sig => $data) {
            $seen["cpu_peak|$sig"] = true;
            $this->upsert($server, 'cpu_peak', $sig, $data);
        }

        foreach ($this->detectMysql($server) as $sig => $data) {
            $seen["mysql_query|$sig"] = true;
            $this->upsert($server, 'mysql_query', $sig, $data);
        }

        // Auto-resolver: incidencias abiertas que ya NO aparecen (y llevan un
        // rato sin verse) pasan solas a "resueltas". Nunca se tocan las que el
        // usuario marcó "no aplica" ni las que movió a mano.
        PerfIssue::where('server_id', $server->id)
            ->whereIn('status', ['por_revisar', 'optimizando'])
            ->where('status_manual', false)
            ->where('last_seen_at', '<', now()->subMinutes(30))
            ->get()
            ->each(function (PerfIssue $issue) use ($seen) {
                if (! isset($seen["{$issue->kind}|{$issue->signature}"])) {
                    $issue->update(['status' => 'resuelta', 'resolved_at' => now()]);
                }
            });

        $issues = PerfIssue::where('server_id', $server->id)
            ->orderByDesc('score')
            ->get();

        $board = [];
        foreach (array_keys(PerfIssue::COLUMNS) as $col) {
            $board[$col] = $issues->where('status', $col)->values()->all();
        }

        return $board;
    }

    /** Inserta o actualiza una incidencia respetando el estado que fijó el usuario. */
    private function upsert(Server $server, string $kind, string $signature, array $data): void
    {
        $issue = PerfIssue::firstOrNew(['server_id' => $server->id, 'kind' => $kind, 'signature' => $signature]);

        $isNew = ! $issue->exists;
        if ($isNew) {
            $issue->status = 'por_revisar';
            $issue->first_detected_at = now();
        } elseif ($issue->status === 'resuelta' && ! $issue->status_manual) {
            // Reapareció una que se había resuelto sola → reabrir para revisar.
            $issue->status = 'por_revisar';
            $issue->resolved_at = null;
        }
        // Si está en "aceptada" (no aplica) o el usuario la movió a mano, se
        // conserva su columna; solo se refrescan los datos.

        $issue->fill([
            'title'        => $data['title'],
            'summary'      => $data['summary'],
            'ai_cause'     => $data['ai_cause'],
            'ai_prompt'    => $data['ai_prompt'],
            'severity'     => $data['severity'],
            'metrics'      => $data['metrics'],
            'occurrences'  => $data['occurrences'] ?? 1,
            'score'        => $data['score'],
            'link'         => $data['link'] ?? null,
            'last_seen_at' => now(),
        ]);
        $issue->save();
    }

    // ── Detección de picos de CPU (desde el histórico guardado) ──────────────

    /** @return array<string, array<string, mixed>> */
    private function detectCpuPeaks(Server $server): array
    {
        $bar = max(70, (int) config('dashboard.cpu_peak_threshold', 50));

        $samples = MetricSample::where('server_id', $server->id)
            ->where('sampled_at', '>=', now()->subDays(7))
            ->where('cpu_pct', '>=', $bar)
            ->orderBy('sampled_at')
            ->get();

        $groups = [];
        foreach ($samples as $s) {
            $culprit = $s->top_container ?: $this->firstWord($s->top_cpu_cmd) ?: 'sistema';
            $sig = $this->slug($culprit);
            $g = &$groups[$sig];
            $g['culprit']   = $culprit;
            $g['container'] = $s->top_container;
            $g['process']   = $s->top_cpu_cmd;
            $g['count']     = ($g['count'] ?? 0) + 1;
            $g['maxCpu']    = max($g['maxCpu'] ?? 0, (int) $s->cpu_pct);
            $g['maxLoad']   = max($g['maxLoad'] ?? 0, (float) $s->load1);
            $g['first']     = $g['first'] ?? $s->sampled_at;
            $g['last']      = $s->sampled_at;
            $g['hours'][(int) $s->sampled_at->format('G')] = ($g['hours'][(int) $s->sampled_at->format('G')] ?? 0) + 1;
            unset($g);
        }

        $out = [];
        foreach ($groups as $sig => $g) {
            $sev = $g['maxCpu'] >= 90 ? 'critica' : ($g['maxCpu'] >= 80 ? 'alta' : 'media');
            $peakHour = $g['hours'] ? array_search(max($g['hours']), $g['hours']) : null;
            $cause = $this->cpuCause($g, $peakHour);

            $out[$sig] = [
                'title'       => 'Picos de CPU · '.$g['culprit'],
                'summary'     => $g['count'].' pico(s) en 7 días · máx '.$g['maxCpu'].'% CPU',
                'ai_cause'    => $cause,
                'ai_prompt'   => $this->cpuPrompt($server, $g, $peakHour, $cause),
                'severity'    => $sev,
                'occurrences' => $g['count'],
                'score'       => $g['maxCpu'] + $g['count'] / 100,   // ordena por severidad y frecuencia
                'metrics'     => [
                    'tipo'       => 'cpu',
                    'culpable'   => $g['culprit'],
                    'contenedor' => $g['container'],
                    'proceso'    => $g['process'],
                    'max_cpu'    => $g['maxCpu'],
                    'max_load'   => $g['maxLoad'],
                    'veces'      => $g['count'],
                    'hora_pico'  => $peakHour,
                    'primero'    => $g['first']->format('d/m H:i'),
                    'ultimo'     => $g['last']->format('d/m H:i'),
                ],
                'link' => null,
            ];
        }

        return $out;
    }

    private function cpuCause(array $g, ?int $peakHour): string
    {
        $c = strtolower((string) ($g['container'] ?? '').' '.($g['process'] ?? ''));
        $when = $peakHour !== null ? ' Los picos se concentran alrededor de las '.$peakHour.':00 (hora Colombia), lo que sugiere una tarea programada o de uso.' : '';

        if (preg_match('/mysql|mariadb|maria|percona|postgres/', $c)) {
            return 'El culpable es el contenedor de base de datos. Casi seguro son consultas pesadas o sin índice — revisa las incidencias de tipo «Consulta MySQL» de este tablero, que traen el plan de optimización.'.$when;
        }
        if (preg_match('/dockerd|containerd/', $c)) {
            return 'Aparece «dockerd» como proceso, pero eso solo indica que la carga viene de ALGÚN contenedor (dockerd es el motor). El pico real es de una app dentro de un contenedor; si es la base de datos, revisa las consultas MySQL.'.$when;
        }
        if (preg_match('/php|fpm|node|artisan|queue|worker/', $c)) {
            return 'El proceso es de la aplicación (PHP/Node/colas). Suele ser un trabajo en segundo plano, una cola o un endpoint costoso.'.$when;
        }

        return 'Proceso «'.($g['process'] ?: $g['culprit']).'». Conviene confirmar si es una tarea programada (cron), un respaldo o un pico de tráfico.'.$when;
    }

    private function cpuPrompt(Server $server, array $g, ?int $peakHour, string $cause): string
    {
        return implode("\n", [
            'Actúa como ingeniero SRE (Linux/Docker). Analiza estos picos de CPU recurrentes y dame la causa y cómo evitarlos.',
            '',
            '## Servidor: '.$server->name.' ('.$server->host.')',
            '## Incidencia: picos de CPU atribuidos a "'.$g['culprit'].'"',
            '- Ocurrencias (7 días): '.$g['count'],
            '- CPU máxima: '.$g['maxCpu'].'% · carga máx (1m): '.$g['maxLoad'],
            '- Proceso: '.($g['process'] ?? '—'),
            '- Contenedor Docker: '.($g['container'] ?? '—'),
            $peakHour !== null ? '- Hora en que más se concentran: '.$peakHour.':00 (hora Colombia)' : '',
            '- Primero visto: '.$g['first']->format('d/m/Y H:i').' · último: '.$g['last']->format('d/m/Y H:i'),
            '',
            '## Diagnóstico preliminar del panel',
            $cause,
            '',
            '## Qué necesito',
            '1. Causa más probable de estos picos.',
            '2. Comandos exactos para confirmarla.',
            '3. Cómo reducirlos (límite de CPU al contenedor, mover tareas de horario, optimizar consultas, etc.).',
            'Si te falta información, dime qué comando ejecutar.',
        ]);
    }

    // ── Detección de consultas MySQL pesadas ─────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function detectMysql(Server $server): array
    {
        try {
            $report = $this->monitor->queryReport($server);
        } catch (\Throwable) {
            return [];
        }
        if (empty($report['available'])) {
            return [];
        }

        $queries = $this->advisor->analyze($report, $server);
        $sevMap  = ['critica' => 'critica', 'alta' => 'alta', 'media' => 'media'];

        $out = [];
        foreach ($queries as $q) {
            $sig = $q['digest'] !== '' ? $q['digest'] : md5($q['query']);
            $out[$sig] = [
                'title'     => 'Consulta MySQL · '.$q['db'],
                'summary'   => number_format((int) $q['total_s']).' s totales · '.number_format($q['execs']).' ejecuciones · '.($q['avg_ms'] >= 1000 ? round($q['avg_ms'] / 1000, 1).' s' : $q['avg_ms'].' ms').' prom.',
                'ai_cause'  => $q['findings'][0] ?? 'Consulta con alto costo acumulado.',
                'ai_prompt' => $q['ai_prompt'],
                'severity'  => $sevMap[$q['severity']] ?? 'media',
                'score'     => (float) $q['total_s'],
                'metrics'   => [
                    'tipo'         => 'mysql',
                    'db'           => $q['db'],
                    'query'        => $q['query'],
                    'total_s'      => (int) $q['total_s'],
                    'execs'        => $q['execs'],
                    'avg_ms'       => $q['avg_ms'],
                    'no_index_pct' => $q['no_index_pct'],
                    'ratio'        => $q['ratio'],
                    'findings'     => $q['findings'],
                    'last_seen'    => $q['last_seen_local'] ?? null,
                ],
                'link' => route('dashboard.servers.queries', $server),
            ];
        }

        return $out;
    }

    // ── Utilidades ───────────────────────────────────────────────────────────

    private function firstWord(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        $w = preg_split('/\s+/', $s)[0];

        return basename($w); // /usr/bin/dockerd → dockerd
    }

    private function slug(string $s): string
    {
        return substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?: 'x', 0, 80);
    }
}
