<?php

namespace App\Http\Controllers;

use App\Models\PerfIssue;
use App\Models\PerfPlan;
use App\Models\Repository;
use App\Models\Server;
use App\Services\AiPlanner;
use App\Services\ClaudeOAuth;
use App\Services\GitHubService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planes de optimización con IA para las incidencias del tablero.
 *
 * Flujo: el usuario da «Planear» en una incidencia MySQL → se mapea la
 * consulta a su repositorio de GitHub → la IA investiga los archivos y genera
 * el plan transmitiéndolo en vivo al modal → el usuario lo lee, lo ajusta
 * conversando con la IA y, si quiere, lo publica en GitHub (rama + PR).
 * Cada paso queda registrado en la trazabilidad de la incidencia.
 */
class PlanController extends Controller
{
    public function __construct(
        private AiPlanner $planner,
        private GitHubService $github,
        private ClaudeOAuth $oauth,
    ) {}

    /** Estado del plan (para abrir el modal): mapeo, credenciales y plan actual. */
    public function state(Server $server, PerfIssue $issue)
    {
        abort_unless($issue->server_id === $server->id, 404);

        $repo = $this->planner->repoFor($issue);
        $plan = $issue->plan;

        return response()->json([
            'ok'                => true,
            'kind'              => $issue->kind,
            'db'                => $this->planner->dbOf($issue),
            'ai_configured'     => $this->planner->configured(),
            'ai_mode'           => $this->planner->authMode(),
            'has_api_key'       => $this->planner->hasApiKey(),
            'oauth_connected'   => $this->oauth->connected(),
            'oauth_account'     => $this->oauth->account(),
            'github_configured' => $this->github->configured(),
            'repo'              => $repo ? ['id' => $repo->id, 'full_name' => $repo->full_name] : null,
            'repos'             => Repository::where('archived', false)->orderBy('full_name')
                ->get(['id', 'full_name', 'language'])->toArray(),
            'plan'              => $plan ? [
                'status'          => $plan->status,
                'plan'            => $plan->plan,
                'chat'            => $plan->chat ?? [],
                'error'           => $plan->error,
                'repository'      => $plan->repository_full_name,
                'investigation'   => $plan->investigation,
                'generated_at'    => optional($plan->generated_at)->format('d/m/Y H:i'),
                'github_pr_url'   => $plan->github_pr_url,
                'github_file_url' => $plan->github_file_url,
                'pushed_at'       => optional($plan->pushed_at)->format('d/m/Y H:i'),
            ] : null,
        ]);
    }

    /** Guarda el mapeo «la base de datos de esta consulta pertenece a este repo». */
    public function mapRepo(Request $request, Server $server, PerfIssue $issue)
    {
        abort_unless($issue->server_id === $server->id, 404);
        $repo = Repository::findOrFail((int) $request->input('repository_id'));

        $this->planner->mapRepo($issue, $repo);
        $issue->logEvent('movida', "Consulta mapeada al repositorio {$repo->full_name}");

        return response()->json(['ok' => true, 'repo' => ['id' => $repo->id, 'full_name' => $repo->full_name]]);
    }

    /** Guarda la clave de la API de Anthropic (cifrada) y activa ese modo. */
    public function saveAiKey(Request $request)
    {
        $request->validate(['api_key' => 'required|string|min:20']);
        AiPlanner::saveKey($request->input('api_key'));
        AiPlanner::saveMode('api_key');

        return response()->json(['ok' => true]);
    }

    /** Cambia el modo de conexión con la IA: «api_key» o «oauth». */
    public function setAiMode(Request $request)
    {
        $mode = (string) $request->input('mode');
        if ($mode === 'oauth' && ! $this->oauth->connected()) {
            return response()->json(['ok' => false, 'error' => 'Primero conecta tu cuenta de Claude.'], 422);
        }
        AiPlanner::saveMode($mode);

        return response()->json(['ok' => true, 'mode' => $this->planner->authMode()]);
    }

    /** Paso 1 del login por cuenta de Claude: entrega la URL de autorización. */
    public function oauthStart()
    {
        return response()->json(['ok' => true, 'url' => $this->oauth->authorizeUrl()]);
    }

    /** Paso 2: canjea el código que pegó el usuario y activa el modo cuenta. */
    public function oauthFinish(Request $request)
    {
        $request->validate(['code' => 'required|string|min:6']);
        try {
            $this->oauth->exchange($request->input('code'));
            AiPlanner::saveMode('oauth');

            return response()->json(['ok' => true, 'account' => $this->oauth->account()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Desconecta la cuenta de Claude y vuelve al modo de clave de API. */
    public function oauthDisconnect()
    {
        $this->oauth->disconnect();
        AiPlanner::saveMode('api_key');

        return response()->json(['ok' => true]);
    }

    /**
     * Genera el plan transmitiéndolo EN VIVO (Server-Sent Events): fases de
     * investigación, razonamiento de la IA y el plan texto a texto.
     */
    public function stream(Server $server, PerfIssue $issue): StreamedResponse
    {
        abort_unless($issue->server_id === $server->id, 404);
        abort_unless($issue->kind === 'mysql_query', 422, 'La planeación con IA aplica a consultas MySQL.');

        return response()->stream(function () use ($issue) {
            @set_time_limit(0);
            $emit = function (string $event, mixed $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode(is_array($data) ? $data : ['m' => $data], JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
            };

            try {
                if (! $this->planner->configured()) {
                    $emit('error', 'Falta la clave de la API de Anthropic. Guárdala en el modal y reintenta.');

                    return;
                }
                $repo = $this->planner->repoFor($issue);
                if (! $repo) {
                    $emit('error', 'Esta consulta aún no está mapeada a un repositorio. Elige el proyecto y reintenta.');

                    return;
                }

                // La incidencia pasa a «En planeación» y queda en la trazabilidad.
                if ($issue->status !== 'planeando') {
                    $issue->update(['status' => 'planeando', 'status_manual' => true, 'resolved_at' => null]);
                }
                $issue->logEvent('plan_iniciado', "Planeación con IA iniciada (repo {$repo->full_name})");

                $plan = PerfPlan::firstOrNew(['perf_issue_id' => $issue->id]);
                $plan->fill([
                    'repository_id'        => $repo->id,
                    'repository_full_name' => $repo->full_name,
                    'status'               => 'investigando',
                    'error'                => null,
                    'model'                => $this->planner->model(),
                ])->save();

                $emit('fase', ['fase' => 'investigando']);
                $inv = $this->planner->investigate($issue, $repo, fn ($ev, $m) => $emit($ev, $m));
                $plan->update(['investigation' => ['tables' => $inv['tables'], 'files' => $inv['files'], 'branch' => $inv['branch']]]);

                $emit('fase', ['fase' => 'generando']);
                $plan->update(['status' => 'generando']);
                $texto = $this->planner->streamPlan($issue, $repo, $inv, fn ($ev, $m) => $emit($ev, $m));

                $plan->update(['status' => 'listo', 'plan' => $texto, 'generated_at' => now()]);
                $issue->logEvent('plan_listo', 'La IA terminó el plan de optimización ('.count($inv['files']).' archivos investigados)');

                $emit('listo', ['plan' => $texto]);
            } catch (\Throwable $e) {
                if (isset($plan) && $plan->exists) {
                    $plan->update(['status' => 'error', 'error' => $e->getMessage()]);
                }
                $issue->logEvent('plan_error', 'Falló la generación del plan: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));
                $emit('error', $e->getMessage());
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',   // nginx: no bufferizar, queremos verlo en vivo
        ]);
    }

    /** Chat con la IA para leer/ajustar el plan (respuesta transmitida en vivo). */
    public function chat(Request $request, Server $server, PerfIssue $issue): StreamedResponse
    {
        abort_unless($issue->server_id === $server->id, 404);
        $request->validate(['message' => 'required|string|max:4000']);
        $message = (string) $request->input('message');

        $plan = $issue->plan;
        abort_unless($plan && $plan->status === 'listo', 422, 'Primero genera el plan.');

        return response()->stream(function () use ($plan, $issue, $message) {
            @set_time_limit(0);
            $emit = function (string $event, mixed $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode(is_array($data) ? $data : ['m' => $data], JSON_UNESCAPED_UNICODE)."\n\n";
                @ob_flush();
                flush();
            };

            try {
                $plan->pushChat('user', $message);
                $out = $this->planner->streamChat($plan, $message, fn ($ev, $m) => $emit($ev, $m));
                $plan->pushChat('assistant', $out['reply']);

                if ($out['plan'] !== null) {
                    $plan->update(['plan' => $out['plan'], 'generated_at' => now()]);
                    $issue->logEvent('plan_modificado', 'Plan ajustado en el chat con la IA');
                }

                $emit('listo', ['reply' => $out['reply'], 'plan_actualizado' => $out['plan'] !== null, 'plan' => $out['plan']]);
            } catch (\Throwable $e) {
                $emit('error', $e->getMessage());
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** Publica el plan en GitHub: rama nueva + archivo Markdown + pull request. */
    public function publish(Server $server, PerfIssue $issue)
    {
        abort_unless($issue->server_id === $server->id, 404);

        $plan = $issue->plan;
        if (! $plan || $plan->status !== 'listo' || empty($plan->plan)) {
            return response()->json(['ok' => false, 'error' => 'Primero genera el plan con la IA.'], 422);
        }
        if (! $this->github->configured()) {
            return response()->json(['ok' => false, 'error' => 'GitHub no está conectado.'], 422);
        }

        $full = $plan->repository_full_name;
        try {
            $base   = $plan->investigation['branch'] ?? $this->github->defaultBranch($full);
            $branch = 'optimizacion/consulta-'.$issue->id;
            $path   = 'docs/optimizaciones/consulta-'.$issue->id.'.md';

            $this->github->createBranch($full, $branch, $base);
            $fileUrl = $this->github->commitFile(
                $full, $branch, $path,
                $this->planDocument($issue, $plan),
                'docs: plan de optimización para consulta MySQL #'.$issue->id.' ('.($issue->metrics['db'] ?? 'BD').')'
            );
            $prUrl = $this->github->createPullRequest(
                $full, $branch, $base,
                '⚡ Optimización de consulta MySQL · '.($issue->metrics['db'] ?? 'BD').' · incidencia #'.$issue->id,
                $this->prBody($issue, $plan)
            );

            $plan->update([
                'github_branch'   => $branch,
                'github_pr_url'   => $prUrl,
                'github_file_url' => $fileUrl,
                'pushed_at'       => now(),
            ]);
            $issue->logEvent('plan_publicado', 'Plan publicado en GitHub: '.$prUrl);

            return response()->json(['ok' => true, 'pr_url' => $prUrl, 'file_url' => $fileUrl, 'branch' => $branch]);
        } catch (\Throwable $e) {
            $issue->logEvent('plan_error', 'Falló la publicación en GitHub: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Documento Markdown que se sube al repositorio (plan + trazabilidad). */
    private function planDocument(PerfIssue $issue, PerfPlan $plan): string
    {
        $m = $issue->metrics ?? [];

        return implode("\n", [
            '# Plan de optimización · consulta MySQL',
            '',
            '> Generado con IA desde el panel de infraestructura (incidencia #'.$issue->id.').',
            '',
            '| Dato | Valor |',
            '|---|---|',
            '| Base de datos | '.($m['db'] ?? '—').' |',
            '| Tiempo total acumulado | '.number_format((int) ($m['total_s'] ?? 0)).' s |',
            '| Ejecuciones | '.number_format((int) ($m['execs'] ?? 0)).' |',
            '| Promedio | '.($m['avg_ms'] ?? '?').' ms |',
            '| % sin índice | '.($m['no_index_pct'] ?? '?').'% |',
            '| Detectada | '.optional($issue->first_detected_at)->format('d/m/Y H:i').' (hora Colombia) |',
            '| Reincidencias | '.$issue->reopened_count.' |',
            '| Plan generado | '.optional($plan->generated_at)->format('d/m/Y H:i').' |',
            '',
            '## Consulta',
            '```sql',
            (string) ($m['query'] ?? ''),
            '```',
            '',
            '---',
            '',
            (string) $plan->plan,
        ]);
    }

    private function prBody(PerfIssue $issue, PerfPlan $plan): string
    {
        $m = $issue->metrics ?? [];
        $files = collect($plan->investigation['files'] ?? [])->pluck('path')->implode("\n- ");

        return implode("\n", [
            'Plan de optimización generado con IA desde el panel de infraestructura.',
            '',
            '**Consulta afectada** (BD `'.($m['db'] ?? '—').'`): '.number_format((int) ($m['total_s'] ?? 0)).' s acumulados · '.number_format((int) ($m['execs'] ?? 0)).' ejecuciones · '.($m['avg_ms'] ?? '?').' ms promedio.',
            '',
            '**Archivos investigados:**',
            '- '.($files ?: '—'),
            '',
            'El plan completo está en `docs/optimizaciones/consulta-'.$issue->id.'.md` dentro de esta rama.',
            '',
            '_Trazabilidad: incidencia #'.$issue->id.' · detectada el '.optional($issue->first_detected_at)->format('d/m/Y H:i').' · '.$issue->reopened_count.' reincidencia(s)._',
        ]);
    }
}
