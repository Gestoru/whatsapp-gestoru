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
        $r = $this->http()->get('/user');

        return $r->successful() ? $r->json() : null;
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
