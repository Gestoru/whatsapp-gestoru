<?php

namespace App\Http\Controllers;

use App\Models\Repository;
use App\Services\GitHubService;
use Illuminate\Http\Request;

class RepositoryController extends Controller
{
    public function __construct(private GitHubService $github) {}

    /** Módulo Repositorios: tabla con filtros, buscador y documentación. */
    public function index()
    {
        try {
            $repos = Repository::orderByDesc('pushed_at')->orderBy('full_name')->get();
        } catch (\Throwable $e) {
            // La tabla aún no existe (faltan migraciones en el servidor).
            return view('dashboard.repositories', [
                'repos' => collect(), 'owners' => collect(), 'languages' => collect(),
                'configured' => false, 'viewer' => null, 'lastSync' => null,
                'stats' => ['total' => 0, 'private' => 0, 'archived' => 0, 'orgs' => 0],
                'bootError' => 'Falta preparar la base de datos del módulo. En el servidor corre: '
                    .'cd /opt/gestoru-dashboard && php artisan migrate --force (o vuelve a ejecutar el instalador).',
            ]);
        }

        return view('dashboard.repositories', [
            'repos'        => $repos,
            'owners'       => $repos->pluck('owner')->unique()->filter()->sort()->values(),
            'languages'    => $repos->pluck('language')->unique()->filter()->sort()->values(),
            'configured'   => $this->github->configured(),
            'viewer'       => $this->github->configured() ? $this->github->viewer() : null,
            'lastSync'     => $repos->max('synced_at'),
            'bootError'    => null,
            'stats'        => [
                'total'    => $repos->count(),
                'private'  => $repos->where('visibility', 'private')->count(),
                'archived' => $repos->where('archived', true)->count(),
                'orgs'     => $repos->pluck('owner')->unique()->count(),
            ],
        ]);
    }

    /** Guarda el token de GitHub (cifrado). */
    public function connect(Request $request)
    {
        $data = $request->validate(['github_token' => 'required|string|max:255']);
        GitHubService::saveCredentials($data['github_token']);

        return redirect()->to(route('dashboard.config').'#github')
            ->with('status', 'Token de GitHub guardado. Ahora presiona «Sincronizar repositorios».');
    }

    /** Trae todos los repos de GitHub a la base de datos. */
    public function sync()
    {
        $r = $this->github->syncToDatabase();

        if ($r['ok']) {
            return redirect()->route('dashboard.repositories')
                ->with('status', "✅ GitHub sincronizado: {$r['total']} repositorios ({$r['created']} nuevos, {$r['updated']} actualizados).");
        }

        return redirect()->route('dashboard.repositories')->with('error', 'GitHub: '.$r['error']);
    }

    /** Guarda la documentación (nota) que el usuario escribe para un repo. */
    public function updateNote(Request $request, Repository $repository)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);
        $repository->update(['notes' => $data['notes'] ?? null]);

        return redirect()->route('dashboard.repositories')->with('status', "Documentación de «{$repository->name}» guardada.");
    }
}
