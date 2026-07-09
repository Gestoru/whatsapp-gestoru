<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ThinkingDelta;
use App\Models\PerfIssue;
use App\Models\PerfPlan;
use App\Models\Repository;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Planificador de optimizaciones con IA.
 *
 * Cuando el usuario le da «Planear» a una incidencia MySQL del tablero:
 *  1. Mapea la consulta a su repositorio de GitHub (por base de datos).
 *  2. Investiga el repositorio: detecta las tablas de la consulta y lee los
 *     archivos relevantes (migraciones, modelos, controladores).
 *  3. Le pide a Claude un plan de optimización y lo transmite en vivo al modal.
 *  4. Permite ajustar el plan conversando con la IA y subirlo a GitHub.
 */
class AiPlanner
{
    public const KEY_SETTING   = 'anthropic_api_key';
    public const MODEL_SETTING = 'anthropic_model';
    public const MODE_SETTING  = 'ai_auth_mode';   // api_key | oauth

    /** Máximo de archivos del repo que se incluyen en el contexto de la IA. */
    private const MAX_FILES = 8;

    /** Máximo de caracteres por archivo (para no desbordar el contexto). */
    private const MAX_FILE_CHARS = 7000;

    public function __construct(
        private GitHubService $github,
        private ClaudeOAuth $oauth,
    ) {}

    // ── Credenciales ─────────────────────────────────────────────────────────

    /** Modo de conexión con la IA: «api_key» o «oauth» (cuenta de Claude). */
    public function authMode(): string
    {
        $mode = Setting::get(self::MODE_SETTING);
        if (in_array($mode, ['api_key', 'oauth'], true)) {
            return $mode;
        }

        // Sin elección explícita: si hay cuenta conectada úsala; si no, API key.
        return $this->oauth->connected() ? 'oauth' : 'api_key';
    }

    public static function saveMode(string $mode): void
    {
        if (in_array($mode, ['api_key', 'oauth'], true)) {
            Setting::put(self::MODE_SETTING, $mode);
        }
    }

    public function configured(): bool
    {
        return $this->authMode() === 'oauth'
            ? $this->oauth->connected()
            : $this->apiKey() !== null;
    }

    public static function saveKey(?string $key): void
    {
        if (! empty($key)) {
            Setting::put(self::KEY_SETTING, Crypt::encryptString(trim($key)));
        }
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== null;
    }

    private function apiKey(): ?string
    {
        $raw = Setting::get(self::KEY_SETTING);
        try {
            return $raw ? Crypt::decryptString($raw) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function model(): string
    {
        return Setting::get(self::MODEL_SETTING) ?: (string) config('dashboard.ai_model');
    }

    private function client(): Client
    {
        // Cuenta de Claude (suscripción): el SDK maneja el Bearer, el header
        // beta de OAuth y la renovación del token por su cuenta.
        if ($this->authMode() === 'oauth') {
            return new Client(credentials: $this->oauth->credential());
        }

        return new Client(apiKey: $this->apiKey());
    }

    /**
     * Envuelve el system prompt. Con la cuenta de Claude (OAuth), el primer
     * bloque debe declarar la identidad de Claude Code, que es como opera el
     * modo de inferencia por suscripción.
     *
     * @return string|array<int, array<string, string>>
     */
    private function wrapSystem(string $text): string|array
    {
        if ($this->authMode() === 'oauth') {
            return [
                ['type' => 'text', 'text' => "You are Claude Code, Anthropic's official CLI for Claude."],
                ['type' => 'text', 'text' => $text],
            ];
        }

        return $text;
    }

    // ── Mapeo consulta → repositorio (por base de datos) ─────────────────────

    /** Repositorio mapeado para la base de datos de la incidencia (si existe). */
    public function repoFor(PerfIssue $issue): ?Repository
    {
        $db = $this->dbOf($issue);
        if ($db === null) {
            return null;
        }
        $map = $this->repoMap();
        $id = $map[$db] ?? null;

        return $id ? Repository::find($id) : null;
    }

    /** Guarda el mapeo «esta base de datos pertenece a este repositorio». */
    public function mapRepo(PerfIssue $issue, Repository $repo): void
    {
        $db = $this->dbOf($issue);
        if ($db === null) {
            return;
        }
        $map = $this->repoMap();
        $map[$db] = $repo->id;
        Setting::put('db_repo_map', json_encode($map));
    }

    public function dbOf(PerfIssue $issue): ?string
    {
        $db = $issue->metrics['db'] ?? null;

        return is_string($db) && $db !== '' ? $db : null;
    }

    /** @return array<string, int> base de datos → id del repositorio */
    private function repoMap(): array
    {
        $raw = Setting::get('db_repo_map');
        $map = $raw ? json_decode($raw, true) : [];

        return is_array($map) ? $map : [];
    }

    // ── Investigación del repositorio ────────────────────────────────────────

    /**
     * Investiga el repositorio: detecta las tablas que usa la consulta y lee
     * los archivos que probablemente la generan. $emit recibe mensajes de
     * progreso para mostrarlos en vivo en el modal.
     *
     * @return array{tables: array<int,string>, files: array<int, array{path:string, chars:int}>, contents: array<string,string>, branch: string}
     */
    public function investigate(PerfIssue $issue, Repository $repo, callable $emit): array
    {
        $query  = (string) ($issue->metrics['query'] ?? '');
        $tables = $this->tablesFromSql($query);
        $emit('paso', 'Tablas detectadas en la consulta: '.($tables ? implode(', ', $tables) : 'ninguna reconocible'));

        $branch = $repo->default_branch ?: $this->github->defaultBranch($repo->full_name);
        $emit('paso', "Descargando el árbol de archivos de {$repo->full_name} (rama {$branch})…");
        $paths = $this->github->tree($repo->full_name, $branch);
        $emit('paso', 'Repositorio con '.number_format(count($paths)).' archivos. Buscando los relacionados…');

        $candidates = $this->rankPaths($paths, $tables);
        $files = [];
        $contents = [];
        foreach (array_slice($candidates, 0, self::MAX_FILES) as $path) {
            $emit('archivo', $path);
            $body = $this->github->fileContent($repo->full_name, $path, $branch);
            if ($body === null) {
                continue;
            }
            if (strlen($body) > self::MAX_FILE_CHARS) {
                $body = substr($body, 0, self::MAX_FILE_CHARS)."\n… (archivo recortado)";
            }
            $files[] = ['path' => $path, 'chars' => strlen($body)];
            $contents[$path] = $body;
        }

        $emit('paso', 'Investigación terminada: '.count($files).' archivo(s) leído(s).');

        return ['tables' => $tables, 'files' => $files, 'contents' => $contents, 'branch' => $branch];
    }

    /** Extrae los nombres de tabla de una consulta SQL (FROM/JOIN/UPDATE/INTO). */
    public function tablesFromSql(string $sql): array
    {
        preg_match_all('/\b(?:from|join|update|into)\s+`?([a-z0-9_]+)`?/i', $sql, $m);
        $tables = array_values(array_unique(array_map('strtolower', $m[1] ?? [])));

        // Palabras que no son tablas (aparecen tras FROM en funciones, etc.)
        return array_values(array_diff($tables, ['select', 'dual', 'unix_timestamp', 'values']));
    }

    /**
     * Ordena las rutas del repo por probabilidad de estar relacionadas con las
     * tablas de la consulta (migraciones, modelos, controladores, servicios).
     *
     * @param array<int,string> $paths
     * @param array<int,string> $tables
     * @return array<int,string>
     */
    private function rankPaths(array $paths, array $tables): array
    {
        // Variantes de nombre: users → user; order_items → orderitem/OrderItem
        $needles = [];
        foreach ($tables as $t) {
            $needles[] = $t;
            $needles[] = rtrim($t, 's');
            $needles[] = str_replace('_', '', rtrim($t, 's'));
        }
        $needles = array_unique(array_filter($needles, fn ($n) => strlen($n) >= 3));

        $scored = [];
        foreach ($paths as $p) {
            $lp = strtolower($p);
            if (! preg_match('/\.(php|sql|js|ts|py|rb|go|java)$/', $lp)) {
                continue;
            }
            if (str_contains($lp, 'vendor/') || str_contains($lp, 'node_modules/') || str_contains($lp, 'storage/')) {
                continue;
            }

            $score = 0;
            foreach ($needles as $n) {
                if (str_contains($lp, $n)) {
                    $score += 10;
                }
            }
            if ($score === 0) {
                continue;
            }
            // Dónde suele estar la causa: migraciones (índices), modelos y consultas
            if (str_contains($lp, 'migration')) $score += 6;
            if (str_contains($lp, 'models/') || str_contains($lp, 'app/models')) $score += 5;
            if (str_contains($lp, 'controller')) $score += 3;
            if (str_contains($lp, 'services/') || str_contains($lp, 'repositories/')) $score += 3;
            if (str_ends_with($lp, '.sql')) $score += 2;

            $scored[$p] = $score;
        }
        arsort($scored);

        return array_keys($scored);
    }

    // ── Generación del plan (en vivo) ────────────────────────────────────────

    /**
     * Genera el plan de optimización con Claude transmitiendo el texto en vivo.
     * $emit('texto', $delta) por cada fragmento; devuelve el plan completo.
     */
    public function streamPlan(PerfIssue $issue, Repository $repo, array $inv, callable $emit): string
    {
        $stream = $this->client()->messages->createStream(
            maxTokens: 16000,
            messages: [['role' => 'user', 'content' => $this->planPrompt($issue, $repo, $inv)]],
            model: $this->model(),
            system: $this->wrapSystem($this->systemPrompt()),
            thinking: ['type' => 'adaptive'],
            requestOptions: ['timeout' => 600],
        );

        return $this->drain($stream, $emit);
    }

    /**
     * Conversación para leer/ajustar el plan. Si la IA decide modificar el
     * plan, lo devuelve completo dentro de <plan_actualizado>…</plan_actualizado>
     * y aquí se separa la respuesta visible del plan nuevo.
     *
     * @return array{reply: string, plan: ?string}
     */
    public function streamChat(PerfPlan $plan, string $message, callable $emit): array
    {
        $history = [];
        foreach (($plan->chat ?? []) as $m) {
            if (in_array($m['role'] ?? '', ['user', 'assistant'], true) && ! empty($m['content'])) {
                $history[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        $history[] = ['role' => 'user', 'content' => $message];

        $context = implode("\n", [
            'Contexto: el usuario está revisando el plan de optimización que generaste para esta incidencia MySQL.',
            'Repositorio: '.($plan->repository_full_name ?: '—'),
            '',
            '## Plan actual (Markdown)',
            (string) $plan->plan,
            '',
            '## Incidencia',
            (string) $plan->issue?->ai_prompt,
            '',
            'Instrucciones para ti:',
            '- Responde en español, claro y breve.',
            '- Si el usuario pide cambiar el plan, incluye al FINAL de tu respuesta el plan COMPLETO actualizado dentro de <plan_actualizado>…</plan_actualizado> (Markdown). Si solo pregunta, no lo incluyas.',
        ]);

        $stream = $this->client()->messages->createStream(
            maxTokens: 16000,
            messages: $history,
            model: $this->model(),
            system: $this->wrapSystem($this->systemPrompt()."\n\n".$context),
            thinking: ['type' => 'adaptive'],
            requestOptions: ['timeout' => 600],
        );

        $full = $this->drain($stream, $emit, visibleUpTo: '<plan_actualizado>');

        // Separar el plan actualizado (si vino) de la respuesta visible.
        $newPlan = null;
        $reply = $full;
        if (preg_match('/<plan_actualizado>(.*?)(?:<\/plan_actualizado>|$)/s', $full, $m)) {
            $newPlan = trim($m[1]);
            $reply = trim(str_replace($m[0], '', $full));
        }

        return ['reply' => $reply, 'plan' => $newPlan !== '' ? $newPlan : null];
    }

    /**
     * Consume el stream de la API emitiendo los deltas de texto y de
     * razonamiento. Si $visibleUpTo llega, deja de emitir texto al encontrar
     * esa marca (el plan actualizado no se muestra crudo en el chat).
     */
    private function drain(iterable $stream, callable $emit, ?string $visibleUpTo = null): string
    {
        $full = '';
        $silenced = false;
        foreach ($stream as $event) {
            if (! $event instanceof RawContentBlockDeltaEvent) {
                continue;
            }
            $d = $event->delta;
            if ($d instanceof ThinkingDelta) {
                $emit('razonando', $d->thinking);
            } elseif ($d instanceof TextDelta) {
                $full .= $d->text;
                if ($visibleUpTo !== null && ! $silenced && str_contains($full, $visibleUpTo)) {
                    $silenced = true;
                    $emit('texto_fin_visible', '');
                }
                if (! $silenced) {
                    $emit('texto', $d->text);
                }
            }
        }

        return $full;
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Eres un ingeniero senior experto en optimización de MySQL y en el framework del repositorio que se te muestra.',
            'Tu trabajo: generar planes de optimización accionables para consultas lentas detectadas en producción.',
            'Escribe SIEMPRE en español. Sé concreto: índices exactos (CREATE INDEX…), cambios de código con el archivo y la línea aproximada, y cómo verificar la mejora.',
            'No inventes archivos ni columnas: básate solo en lo que se te muestra. Si falta información, dilo y explica cómo obtenerla.',
        ]);
    }

    private function planPrompt(PerfIssue $issue, Repository $repo, array $inv): string
    {
        $m = $issue->metrics ?? [];
        $parts = [
            'Genera un PLAN DE OPTIMIZACIÓN en Markdown para esta consulta MySQL lenta.',
            '',
            '## Incidencia detectada por el panel de monitoreo',
            '- Base de datos: '.($m['db'] ?? '—'),
            '- Tiempo total acumulado: '.number_format((int) ($m['total_s'] ?? 0)).' s',
            '- Ejecuciones: '.number_format((int) ($m['execs'] ?? 0)),
            '- Promedio: '.($m['avg_ms'] ?? '?').' ms',
            '- % sin índice: '.($m['no_index_pct'] ?? '?').'%',
            '- Hallazgos automáticos: '.implode(' | ', (array) ($m['findings'] ?? [])),
            '- Detectada por primera vez: '.optional($issue->first_detected_at)->format('d/m/Y H:i'),
            '- Reincidencias (se resolvió y volvió): '.$issue->reopened_count,
            '',
            '## Consulta (normalizada por performance_schema)',
            '```sql',
            (string) ($m['query'] ?? ''),
            '```',
            '',
            '## Repositorio investigado: '.$repo->full_name.' (rama '.$inv['branch'].')',
            'Tablas detectadas: '.implode(', ', $inv['tables'] ?: ['—']),
            '',
        ];

        foreach ($inv['contents'] as $path => $body) {
            $parts[] = '### Archivo: '.$path;
            $parts[] = '```';
            $parts[] = $body;
            $parts[] = '```';
            $parts[] = '';
        }

        $parts[] = implode("\n", [
            '## Formato del plan (obligatorio)',
            '1. **🎯 Diagnóstico** — por qué esta consulta es lenta (2-4 frases, lenguaje claro).',
            '2. **📍 Origen en el código** — archivo(s) exacto(s) del repo donde nace la consulta.',
            '3. **🛠️ Cambios propuestos** — pasos numerados: SQL exacto de índices (CREATE INDEX…), cambios de código (mostrar antes/después), o migración Laravel si aplica.',
            '4. **✅ Verificación** — cómo comprobar la mejora (EXPLAIN antes/después, qué mirar en este panel).',
            '5. **⚠️ Riesgos** — qué cuidar al aplicar (bloqueos por creación de índices, etc.).',
            'Sé específico y directo; el plan debe poder aplicarse tal cual.',
        ]);

        return implode("\n", $parts);
    }
}
