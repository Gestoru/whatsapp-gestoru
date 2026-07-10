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
                'code_commit_url' => $plan->code_commit_url,
                'code_pushed_at'  => optional($plan->code_pushed_at)->format('d/m/Y H:i'),
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
            $emit = $this->sseEmitter();
            $plan = null;

            try {
                if (! $this->planner->configured()) {
                    $emit('error', 'La IA no está conectada. Elige «🔑 Clave de API» o «👤 Cuenta de Claude» y reintenta.');

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

                // Sin texto: la IA no devolvió nada (credenciales rechazadas, límite
                // de tokens, etc.). Lo tratamos como error para no dejar un plan vacío.
                if (trim((string) $texto) === '') {
                    $plan->update(['status' => 'error', 'error' => 'La IA no devolvió texto.']);
                    $issue->logEvent('plan_error', 'La IA no devolvió texto');
                    $emit('error', 'La IA no devolvió ningún texto.'.$this->aiHint());

                    return;
                }

                $plan->update(['status' => 'listo', 'plan' => $texto, 'generated_at' => now()]);
                $issue->logEvent('plan_listo', 'La IA terminó el plan de optimización ('.count($inv['files']).' archivos investigados)');

                $emit('listo', ['plan' => $texto]);
            } catch (\Throwable $e) {
                report($e);
                if ($plan instanceof PerfPlan && $plan->exists) {
                    $plan->update(['status' => 'error', 'error' => $e->getMessage()]);
                }
                $issue->logEvent('plan_error', 'Falló la generación del plan: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));
                $emit('error', $e->getMessage().$this->aiHint());
            }
        }, 200, $this->sseHeaders());
    }

    /** Cabeceras para Server-Sent Events (sin buffering en nginx). */
    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'Connection'        => 'keep-alive',
            'X-Accel-Buffering' => 'no',   // nginx: no bufferizar, queremos verlo en vivo
        ];
    }

    /**
     * Devuelve una función emit($evento, $datos) que manda un evento SSE y lo
     * vacía de inmediato. Antes desactiva cualquier buffer para que el texto
     * llegue en vivo y no todo al final.
     */
    private function sseEmitter(): callable
    {
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        echo ': conectado'."\n\n";   // comentario inicial: abre el flujo enseguida
        flush();

        return function (string $event, mixed $data): void {
            echo 'event: '.$event."\n";
            echo 'data: '.json_encode(is_array($data) ? $data : ['m' => $data], JSON_UNESCAPED_UNICODE)."\n\n";
            flush();
        };
    }

    /** Pista extra en los errores según el modo de conexión de IA. */
    private function aiHint(): string
    {
        return $this->planner->authMode() === 'oauth'
            ? ' Estás usando «Cuenta de Claude»: las cuentas de suscripción a veces no permiten este uso desde aplicaciones externas. Prueba con «🔑 Clave de API» (enlace «cambiar» arriba).'
            : '';
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
            $emit = $this->sseEmitter();

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
                report($e);
                $emit('error', $e->getMessage().$this->aiHint());
            }
        }, 200, $this->sseHeaders());
    }

    /** Sube la solución a GitHub en UN commit directo a la rama principal (sin PR). */
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
            $branch = $this->github->defaultBranch($full);   // master / main
            $ddl    = $this->extractIndexDdl((string) $plan->plan);

            // Todos los archivos en un solo commit directo a la rama principal.
            $docPath = 'docs/optimizaciones/consulta-'.$issue->id.'.md';
            $files   = [$docPath => $this->planDocument($issue, $plan)];
            $artifacts = [$docPath];

            if ($ddl) {
                $migPath = 'database/migrations/'.now()->format('Y_m_d_His').'_optimiza_'.$ddl['table'].'_incidencia_'.$issue->id.'.php';
                $shPath  = 'scripts/optimizaciones/consulta-'.$issue->id.'.sh';
                $files[$migPath] = $this->buildMigration($ddl, $issue->id);
                $files[$shPath]  = $this->buildShellScript($ddl, $issue);
                $artifacts[]     = $migPath;
                $artifacts[]     = $shPath;
            }

            $commitUrl = $this->github->commitFiles($full, $branch, $files, $this->commitMessage($issue, $ddl));

            $plan->update([
                'github_branch'   => $branch,
                'github_pr_url'   => $commitUrl,   // ahora guarda la URL del commit
                'github_file_url' => 'https://github.com/'.$full.'/blob/'.$branch.'/'.$docPath,
                'pushed_at'       => now(),
            ]);
            $issue->logEvent('plan_publicado', 'Solución commiteada a «'.$branch.'»'.($ddl ? ' (con migración)' : '').': '.$commitUrl);

            return response()->json([
                'ok' => true, 'commit_url' => $commitUrl, 'branch' => $branch,
                'artifacts' => $artifacts, 'has_migration' => (bool) $ddl,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $issue->logEvent('plan_error', 'Falló el commit a GitHub: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Genera los cambios de código propuestos por la IA y los devuelve como diff. */
    public function proposeCode(Server $server, PerfIssue $issue)
    {
        abort_unless($issue->server_id === $server->id, 404);

        $plan = $issue->plan;
        if (! $plan || $plan->status !== 'listo') {
            return response()->json(['ok' => false, 'error' => 'Primero genera el plan con la IA.'], 422);
        }
        if (! $this->planner->configured()) {
            return response()->json(['ok' => false, 'error' => 'La IA no está conectada.'], 422);
        }
        $repo = $this->planner->repoFor($issue);
        if (! $repo) {
            return response()->json(['ok' => false, 'error' => 'La consulta no está mapeada a un repositorio.'], 422);
        }

        try {
            $inv   = $this->planner->investigate($issue, $repo, fn ($e = null, $m = null) => null);
            $edits = $this->planner->proposeEdits($plan, $inv['contents']);
            $plan->update(['edits' => $edits]);

            return response()->json([
                'ok'     => true,
                'count'  => count($edits),
                'branch' => $inv['branch'],
                'files'  => $this->buildDiffs($repo, $inv['branch'], $edits),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => $e->getMessage().$this->aiHint()], 500);
        }
    }

    /** Aplica los cambios aceptados y los sube en un commit directo a la rama principal. */
    public function applyCode(Server $server, PerfIssue $issue)
    {
        abort_unless($issue->server_id === $server->id, 404);

        $plan = $issue->plan;
        if (! $plan || empty($plan->edits)) {
            return response()->json(['ok' => false, 'error' => 'No hay cambios de código propuestos.'], 422);
        }
        if (! $this->github->configured()) {
            return response()->json(['ok' => false, 'error' => 'GitHub no está conectado.'], 422);
        }
        $repo = $this->planner->repoFor($issue);
        if (! $repo) {
            return response()->json(['ok' => false, 'error' => 'La consulta no está mapeada a un repositorio.'], 422);
        }

        try {
            $branch  = $this->github->defaultBranch($repo->full_name);
            $files   = [];
            $applied = 0;
            $skipped = [];

            // Agrupa las ediciones por archivo y aplícalas solo si el fragmento
            // sigue existiendo y es único (si no, se omite para no romper nada).
            $byPath = [];
            foreach ($plan->edits as $e) {
                $byPath[$e['path']][] = $e;
            }
            foreach ($byPath as $path => $edits) {
                $content = $this->github->fileContent($repo->full_name, $path, $branch);
                if ($content === null) {
                    $skipped[] = $path.' (no se pudo leer)';
                    continue;
                }
                $changed = false;
                foreach ($edits as $e) {
                    if (substr_count($content, $e['old_string']) === 1) {
                        $content = str_replace($e['old_string'], $e['new_string'], $content);
                        $changed = true;
                        $applied++;
                    } else {
                        $skipped[] = $path.' — '.($e['explanation'] ?: 'fragmento no ubicable');
                    }
                }
                if ($changed) {
                    $files[$path] = $content;
                }
            }

            if (empty($files)) {
                return response()->json(['ok' => false, 'error' => 'Los fragmentos ya no coinciden con el código actual. Vuelve a proponer los cambios.'], 422);
            }

            $url = $this->github->commitFiles(
                $repo->full_name, $branch, $files,
                'refactor: optimización consulta MySQL #'.$issue->id.' ('.($issue->metrics['db'] ?? 'BD').') · cambios de código'
            );
            $plan->update(['code_commit_url' => $url, 'code_pushed_at' => now()]);
            $issue->logEvent('plan_publicado', 'Cambios de código commiteados a «'.$branch.'» ('.$applied.' cambio/s): '.$url);

            return response()->json([
                'ok' => true, 'commit_url' => $url, 'branch' => $branch,
                'applied' => $applied, 'skipped' => $skipped, 'files' => array_keys($files),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $issue->logEvent('plan_error', 'Falló el commit de código: '.\Illuminate\Support\Str::limit($e->getMessage(), 160));

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Trae los archivos reales de GitHub, ubica cada edición y arma el diff
     * (antes/después) marcando si el fragmento se puede aplicar sin ambigüedad.
     *
     * @param array<int, array{path:string, old_string:string, new_string:string, explanation:string}> $edits
     * @return array<int, array{path:string, hunks:array<int, array<string,string>>}>
     */
    private function buildDiffs(Repository $repo, string $branch, array $edits): array
    {
        $cache = [];
        $byFile = [];
        foreach ($edits as $e) {
            $path = $e['path'];
            if (! array_key_exists($path, $cache)) {
                $cache[$path] = $this->github->fileContent($repo->full_name, $path, $branch);
            }
            $content = $cache[$path];
            $status = 'ok';
            if ($content === null) {
                $status = 'no_file';
            } else {
                $n = substr_count($content, $e['old_string']);
                $status = $n === 0 ? 'not_found' : ($n > 1 ? 'ambiguous' : 'ok');
            }
            $byFile[$path][] = [
                'explanation' => $e['explanation'],
                'before'      => $e['old_string'],
                'after'       => $e['new_string'],
                'status'      => $status,
            ];
        }

        $out = [];
        foreach ($byFile as $path => $hunks) {
            $out[] = ['path' => $path, 'hunks' => $hunks];
        }

        return $out;
    }

    /**
     * Extrae de la sección «🤖 Para aplicar» del plan la sentencia de índice,
     * para poder generar la migración y el script de forma determinista.
     *
     * @return array{table:string, index:string, columns:array<int,string>}|null
     */
    private function extractIndexDdl(string $planMd): ?array
    {
        if (! preg_match_all('/```(?:sql)?\s*(.+?)```/is', $planMd, $blocks)) {
            return null;
        }
        foreach ($blocks[1] as $code) {
            foreach (preg_split('/;\s*[\r\n]|;\s*$/', trim($code)) as $stmt) {
                $stmt = trim((string) $stmt);
                // ALTER TABLE `t` ADD INDEX `name` (`c1`, `c2`)
                if (preg_match('/ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]+)\)/i', $stmt, $mm)) {
                    return $this->normalizeDdl($mm[1], $mm[2], $mm[3]);
                }
                // CREATE INDEX `name` ON `t` (`c1`, `c2`)
                if (preg_match('/CREATE\s+INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]+)\)/i', $stmt, $mm)) {
                    return $this->normalizeDdl($mm[2], $mm[1], $mm[3]);
                }
            }
        }

        return null;
    }

    /** @return array{table:string, index:string, columns:array<int,string>} */
    private function normalizeDdl(string $table, string $index, string $colsRaw): array
    {
        $columns = [];
        foreach (explode(',', $colsRaw) as $c) {
            $c = preg_replace('/\(\d+\)/', '', $c);          // quita longitud tipo (191)
            if (preg_match('/([A-Za-z0-9_]+)/', (string) $c, $cm)) {
                $columns[] = $cm[1];
            }
        }

        return ['table' => $table, 'index' => $index, 'columns' => array_values(array_filter($columns))];
    }

    /** Migración Laravel idempotente que crea el índice propuesto. */
    private function buildMigration(array $ddl, int $issueId): string
    {
        $colsSql = implode(', ', array_map(fn ($c) => '`'.$c.'`', $ddl['columns']));
        $tpl = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Optimización automática generada desde el panel de infraestructura
 * (incidencia #__ID__). Crea el índice `__INDEX__` sobre `__TABLE__` de
 * forma idempotente para acelerar una consulta lenta detectada en producción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('__TABLE__', '__INDEX__')) {
            DB::statement('ALTER TABLE `__TABLE__` ADD INDEX `__INDEX__` (__COLS__)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('__TABLE__', '__INDEX__')) {
            DB::statement('ALTER TABLE `__TABLE__` DROP INDEX `__INDEX__`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        ) !== null;
    }
};

PHP;

        return strtr($tpl, [
            '__ID__'    => (string) $issueId,
            '__TABLE__' => $ddl['table'],
            '__INDEX__' => $ddl['index'],
            '__COLS__'  => $colsSql,
        ]);
    }

    /** Script bash idempotente para aplicar el índice desde consola. */
    private function buildShellScript(array $ddl, PerfIssue $issue): string
    {
        $tpl = <<<'BASH'
#!/usr/bin/env bash
# ────────────────────────────────────────────────────────────────────────────
# Optimización de la consulta MySQL de la incidencia #__ID__ (tabla __TABLE__).
# Crea el índice __INDEX__ de forma IDEMPOTENTE (no falla si ya existe) y
# muestra los índices antes y después para dejar constancia de la mejora.
#
# Uso:
#   DB_DATABASE=__DB__ DB_USERNAME=usuario MYSQL_PWD=clave ./consulta-__ID__.sh
#   (MYSQL_PWD lo lee el cliente mysql automáticamente; no queda en el historial.)
# ────────────────────────────────────────────────────────────────────────────
set -euo pipefail

DB="${DB_DATABASE:-__DB__}"
DBUSER="${DB_USERNAME:-root}"
run(){ mysql -u "$DBUSER" "$DB" -e "$1"; }

echo "== Índices actuales en __TABLE__ =="
run "SHOW INDEX FROM __TABLE__;"

echo
echo "== Creando el índice __INDEX__ si no existe… =="
run "SET @existe := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = '__TABLE__' AND index_name = '__INDEX__'); SET @sql := IF(@existe = 0, 'ALTER TABLE __TABLE__ ADD INDEX __INDEX__ (__COLS__), ALGORITHM=INPLACE, LOCK=NONE', 'DO 0'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;"

echo
echo "== Índices después =="
run "SHOW INDEX FROM __TABLE__;"
echo "✅ Listo. Revisa el promedio de la consulta en el panel en 24-48 h."

BASH;

        return strtr($tpl, [
            '__ID__'    => (string) $issue->id,
            '__TABLE__' => $ddl['table'],
            '__INDEX__' => $ddl['index'],
            '__COLS__'  => implode(', ', $ddl['columns']),
            '__DB__'    => (string) ($issue->metrics['db'] ?? ''),
        ]);
    }

    /** Documento Markdown que se sube al repositorio (metadatos + plan). */
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
            '---',
            '',
            (string) $plan->plan,
        ]);
    }

    /** Mensaje del commit directo a la rama principal. */
    private function commitMessage(PerfIssue $issue, ?array $ddl): string
    {
        $db = $issue->metrics['db'] ?? 'BD';

        return $ddl
            ? 'perf: índice '.$ddl['index'].' en '.$ddl['table'].' para consulta MySQL lenta ('.$db.') · incidencia #'.$issue->id
            : 'docs: plan de optimización consulta MySQL ('.$db.') · incidencia #'.$issue->id;
    }
}
