<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Conecta con la API de GitHub mediante un token personal (fine-grained o
 * clásico, solo lectura) que el usuario genera eligiendo su organización y
 * permisos. Sincroniza los repositorios de sus organizaciones y su cuenta.
 */
class GitHubService
{
    private const API = 'https://api.github.com';

    private ?string $token;

    public function __construct()
    {
        $raw = Setting::get('github_token');
        try {
            $this->token = $raw ? Crypt::decryptString($raw) : null;
        } catch (\Throwable $e) {
            $this->token = null;
        }
    }

    public function configured(): bool
    {
        return ! empty($this->token);
    }

    public static function saveCredentials(?string $token): void
    {
        if (! empty($token)) {
            Setting::put('github_token', Crypt::encryptString(trim($token)));
        }
    }

    private function http()
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent'           => 'NEXO-Infra-Dashboard',
            ])
            ->timeout(20)
            ->baseUrl(self::API);
    }

    /** Datos del usuario/token (para mostrar con quién estamos conectados). */
    public function viewer(): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        try {
            $r = $this->http()->get('/user');

            return $r->successful() ? $r->json() : null;
        } catch (\Throwable $e) {
            return null;   // sin red / token inválido: no rompemos la vista
        }
    }

    /** Organizaciones visibles para el token. */
    public function orgs(): array
    {
        return $this->paginate('/user/orgs');
    }

    /**
     * Todos los repositorios accesibles: los de la cuenta + los de cada
     * organización. Deduplicados por id de GitHub.
     */
    public function repos(): array
    {
        $all = [];

        // Repos de la cuenta (incluye los de orgs donde es miembro, según el token)
        foreach ($this->paginate('/user/repos', ['affiliation' => 'owner,organization_member,collaborator', 'sort' => 'pushed']) as $r) {
            $all[$r['id']] = $r;
        }

        // Refuerzo: repos por organización (tokens fine-grained a veces solo los exponen aquí)
        foreach ($this->orgs() as $org) {
            $login = $org['login'] ?? null;
            if (! $login) {
                continue;
            }
            foreach ($this->paginate("/orgs/{$login}/repos", ['sort' => 'pushed']) as $r) {
                $all[$r['id']] = $r;
            }
        }

        return array_values($all);
    }

    /** Sincroniza los repos a la base de datos. */
    public function syncToDatabase(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'Falta el token de GitHub.'];
        }

        try {
            $repos = $this->repos();
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (empty($repos)) {
            return ['ok' => false, 'error' => 'El token no devolvió repositorios. Revisa que tenga permiso de lectura sobre los repos/organización.'];
        }

        $created = 0;
        $updated = 0;
        foreach ($repos as $r) {
            $repo = Repository::firstOrNew(['github_id' => $r['id']]);
            $existed = $repo->exists;

            $repo->fill([
                'owner'          => $r['owner']['login'] ?? '—',
                'name'           => $r['name'] ?? '',
                'full_name'      => $r['full_name'] ?? '',
                'description'    => $r['description'] ?? null,
                'language'       => $r['language'] ?? null,
                'visibility'     => $r['visibility'] ?? ($r['private'] ?? false ? 'private' : 'public'),
                'html_url'       => $r['html_url'] ?? '',
                'default_branch' => $r['default_branch'] ?? null,
                'stars'          => $r['stargazers_count'] ?? 0,
                'forks'          => $r['forks_count'] ?? 0,
                'open_issues'    => $r['open_issues_count'] ?? 0,
                'topics'         => $r['topics'] ?? [],
                'archived'       => $r['archived'] ?? false,
                'is_fork'        => $r['fork'] ?? false,
                'pushed_at'      => $r['pushed_at'] ?? null,
                'synced_at'      => now(),
            ]);
            $repo->save();

            $existed ? $updated++ : $created++;
        }

        return ['ok' => true, 'total' => count($repos), 'created' => $created, 'updated' => $updated];
    }

    // ── Investigación de repositorios (para los planes de optimización) ──────

    /**
     * Árbol completo de archivos del repo (rutas). Se usa para que la IA
     * investigue dónde vive el código relacionado con una consulta.
     *
     * @return array<int, string> rutas de archivos (blobs)
     */
    public function tree(string $fullName, ?string $branch = null): array
    {
        $branch = $branch ?: $this->defaultBranch($fullName);
        $r = $this->http()->get("/repos/{$fullName}/git/trees/{$branch}", ['recursive' => 1]);
        if (! $r->successful()) {
            throw new \RuntimeException("GitHub no devolvió el árbol de {$fullName}: ".$r->status());
        }

        $paths = [];
        foreach ((array) $r->json('tree') as $node) {
            if (($node['type'] ?? '') === 'blob') {
                $paths[] = $node['path'];
            }
        }

        return $paths;
    }

    /** Contenido de un archivo del repo (decodificado). null si no existe o es muy grande. */
    public function fileContent(string $fullName, string $path, ?string $branch = null): ?string
    {
        $q = $branch ? ['ref' => $branch] : [];
        $r = $this->http()->get("/repos/{$fullName}/contents/{$path}", $q);
        if (! $r->successful()) {
            return null;
        }
        $j = $r->json();
        if (($j['encoding'] ?? '') !== 'base64' || ! isset($j['content'])) {
            return null;
        }

        $raw = base64_decode(str_replace("\n", '', $j['content']), true);

        return $raw === false ? null : $raw;
    }

    /** Rama por defecto del repo (main/master). */
    public function defaultBranch(string $fullName): string
    {
        $r = $this->http()->get("/repos/{$fullName}");

        return $r->successful() ? ($r->json('default_branch') ?: 'main') : 'main';
    }

    // ── Publicación de soluciones (rama + commit + pull request) ─────────────

    /** SHA del último commit de una rama. */
    public function branchSha(string $fullName, string $branch): ?string
    {
        $r = $this->http()->get("/repos/{$fullName}/git/ref/heads/{$branch}");

        return $r->successful() ? $r->json('object.sha') : null;
    }

    /** Crea una rama a partir de la rama base. Si ya existe, la reutiliza. */
    public function createBranch(string $fullName, string $branch, string $fromBranch): void
    {
        if ($this->branchSha($fullName, $branch)) {
            return;   // ya existe
        }
        $sha = $this->branchSha($fullName, $fromBranch);
        if (! $sha) {
            throw new \RuntimeException("No se encontró la rama base «{$fromBranch}» en {$fullName}.");
        }
        $r = $this->http()->post("/repos/{$fullName}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $sha,
        ]);
        if (! $r->successful()) {
            throw new \RuntimeException('GitHub no dejó crear la rama: '.($r->json('message') ?? $r->status()));
        }
    }

    /**
     * Crea o actualiza un archivo en una rama (API de contents).
     * Devuelve la URL del archivo en GitHub.
     */
    public function commitFile(string $fullName, string $branch, string $path, string $content, string $message): string
    {
        // Si el archivo ya existe en la rama necesitamos su sha para actualizarlo.
        $existing = $this->http()->get("/repos/{$fullName}/contents/{$path}", ['ref' => $branch]);
        $payload = [
            'message' => $message,
            'content' => base64_encode($content),
            'branch'  => $branch,
        ];
        if ($existing->successful() && $existing->json('sha')) {
            $payload['sha'] = $existing->json('sha');
        }

        $r = $this->http()->put("/repos/{$fullName}/contents/{$path}", $payload);
        if (! $r->successful()) {
            throw new \RuntimeException('GitHub no dejó guardar el archivo: '.($r->json('message') ?? $r->status()));
        }

        return $r->json('content.html_url') ?? "https://github.com/{$fullName}/blob/{$branch}/{$path}";
    }

    /**
     * Hace UN SOLO commit con varios archivos directamente sobre una rama
     * (por defecto la principal), sin abrir pull request. Usa la Git Data API
     * (blobs → tree → commit → ref) para que quede todo en un único commit.
     *
     * @param array<string, string> $files  ruta => contenido
     * @return string URL del commit en GitHub
     */
    public function commitFiles(string $fullName, string $branch, array $files, string $message): string
    {
        $baseSha = $this->branchSha($fullName, $branch);
        if (! $baseSha) {
            throw new \RuntimeException("No se encontró la rama «{$branch}» en {$fullName}.");
        }

        // Árbol base del último commit.
        $commit = $this->http()->get("/repos/{$fullName}/git/commits/{$baseSha}");
        if (! $commit->successful()) {
            throw new \RuntimeException('GitHub no devolvió el commit base: '.$commit->status());
        }
        $baseTree = $commit->json('tree.sha');

        // Un blob por archivo (los .sh quedan ejecutables con modo 100755).
        $tree = [];
        foreach ($files as $path => $content) {
            $blob = $this->http()->post("/repos/{$fullName}/git/blobs", [
                'content'  => base64_encode($content),
                'encoding' => 'base64',
            ]);
            if (! $blob->successful()) {
                throw new \RuntimeException('GitHub no aceptó el archivo '.$path.': '.($blob->json('message') ?? $blob->status()));
            }
            $tree[] = [
                'path' => ltrim($path, '/'),
                'mode' => str_ends_with($path, '.sh') ? '100755' : '100644',
                'type' => 'blob',
                'sha'  => $blob->json('sha'),
            ];
        }

        $newTree = $this->http()->post("/repos/{$fullName}/git/trees", [
            'base_tree' => $baseTree,
            'tree'      => $tree,
        ]);
        if (! $newTree->successful()) {
            throw new \RuntimeException('GitHub no creó el árbol: '.($newTree->json('message') ?? $newTree->status()));
        }

        $newCommit = $this->http()->post("/repos/{$fullName}/git/commits", [
            'message' => $message,
            'tree'    => $newTree->json('sha'),
            'parents' => [$baseSha],
        ]);
        if (! $newCommit->successful()) {
            throw new \RuntimeException('GitHub no creó el commit: '.($newCommit->json('message') ?? $newCommit->status()));
        }
        $newSha = $newCommit->json('sha');

        // Mueve la rama al nuevo commit (falla si la rama está protegida).
        $ref = $this->http()->patch("/repos/{$fullName}/git/refs/heads/{$branch}", ['sha' => $newSha]);
        if (! $ref->successful()) {
            $msg = $ref->json('message') ?? (string) $ref->status();
            if (str_contains(strtolower($msg), 'protected')) {
                throw new \RuntimeException("La rama «{$branch}» está protegida en {$fullName}; no puedo commitear directo. Quita la protección o usa el flujo con pull request.");
            }
            throw new \RuntimeException('GitHub no movió la rama: '.$msg);
        }

        return $newCommit->json('html_url') ?? "https://github.com/{$fullName}/commit/{$newSha}";
    }

    /**
     * Abre un pull request. Si ya existe uno de esa rama, devuelve su URL.
     *
     * @return string URL del pull request
     */
    public function createPullRequest(string $fullName, string $branch, string $base, string $title, string $body): string
    {
        $r = $this->http()->post("/repos/{$fullName}/pulls", [
            'title' => $title,
            'head'  => $branch,
            'base'  => $base,
            'body'  => $body,
        ]);
        if ($r->successful()) {
            return $r->json('html_url');
        }

        // ¿Ya existe un PR abierto para esa rama? Lo reutilizamos.
        [$owner] = explode('/', $fullName);
        $open = $this->http()->get("/repos/{$fullName}/pulls", ['head' => "{$owner}:{$branch}", 'state' => 'open']);
        if ($open->successful() && ! empty($open->json())) {
            return $open->json()[0]['html_url'];
        }

        throw new \RuntimeException('GitHub no dejó abrir el pull request: '.($r->json('message') ?? $r->status()));
    }

    /**
     * Recorre una colección paginada de la API (100 por página).
     *
     * @return array<int, array<string, mixed>>
     */
    private function paginate(string $path, array $query = []): array
    {
        $out = [];
        $page = 1;
        do {
            $r = $this->http()->get($path, array_merge($query, ['per_page' => 100, 'page' => $page]));
            if (! $r->successful()) {
                if ($page === 1) {
                    throw new \RuntimeException('GitHub respondió '.$r->status().': '.($r->json('message') ?? 'error'));
                }
                break;
            }
            $batch = $r->json();
            if (! is_array($batch) || empty($batch)) {
                break;
            }
            $out = array_merge($out, $batch);
            $page++;
        } while (count($batch) === 100 && $page <= 20);

        return $out;
    }
}
