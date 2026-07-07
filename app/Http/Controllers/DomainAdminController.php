<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Hostname;
use App\Models\Server;
use App\Models\Setting;
use App\Services\DomainInspector;
use App\Services\GoDaddyService;
use App\Services\ServerMonitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainAdminController extends Controller
{
    public function __construct(
        private ServerMonitor $monitor,
        private DomainInspector $inspector,
        private GoDaddyService $godaddy,
    ) {}

    /** Guarda las credenciales de la API de GoDaddy. */
    public function connectGoDaddy(Request $request)
    {
        $data = $request->validate([
            'godaddy_api_key'    => 'required|string|max:200',
            'godaddy_api_secret' => 'nullable|string|max:200',
        ]);

        GoDaddyService::saveCredentials($data['godaddy_api_key'], $data['godaddy_api_secret'] ?? null);

        return redirect()->to(route('dashboard.config').'#godaddy')->with('status', 'Credenciales de GoDaddy guardadas. Ahora presiona «Sincronizar ahora».');
    }

    /** Trae todos los dominios de GoDaddy con su estado. */
    public function syncGoDaddy()
    {
        $r = app(GoDaddyService::class)->syncToDatabase();

        if ($r['ok']) {
            return redirect()->route('dashboard.domains')
                ->with('status', "✅ GoDaddy sincronizado: {$r['total']} dominios ({$r['created']} nuevos, {$r['updated']} actualizados).");
        }

        return redirect()->route('dashboard.domains')->with('error', 'GoDaddy: '.$r['error']);
    }

    /** Dashboard de dominios: agrupados con sus subdominios colapsables. */
    public function index()
    {
        $domains   = Domain::orderByRaw('expires_at IS NULL, expires_at')->get();
        $hostnames = Hostname::with('server')->orderBy('hostname')->get();

        // Agrupar hostnames bajo su dominio raíz
        $grouped = [];
        foreach ($hostnames as $h) {
            $apex = $this->inspector->apexFor($h->hostname);
            if ($apex) {
                $grouped[$apex][] = $h;
            }
        }

        // Las métricas de vencimiento se calculan sobre los dominios activos
        $active = $domains->filter(fn ($d) => $d->is_active);
        $stats = [
            'total'    => $active->count(),
            'due'      => $active->filter(fn ($d) => $d->expiryLevel() === 'due')->count(),
            'warn'     => $active->filter(fn ($d) => $d->expiryLevel() === 'warn')->count(),
            'unknown'  => $active->filter(fn ($d) => $d->expiryLevel() === 'unknown')->count(),
            'inactive' => $domains->filter(fn ($d) => ! $d->is_active)->count(),
        ];

        return view('dashboard.domains', [
            'domains'            => $domains,
            'grouped'            => $grouped,
            'stats'              => $stats,
            'godaddyConfigured'  => $this->godaddy->configured(),
            'godaddyKey'         => Setting::get('godaddy_api_key'),
            'godaddyLastSync'    => Domain::where('registrar', 'godaddy')->max('synced_at'),
        ]);
    }

    /** Escanea todos los servidores activos y registra dominios/subdominios. */
    public function scan()
    {
        $servers = Server::where('is_active', true)->get()
            ->filter(fn ($s) => $s->hasCredentials());

        $found = 0;
        $newDomains = 0;
        $errors = [];

        foreach ($servers as $server) {
            try {
                foreach ($this->monitor->domains($server) as $hostname) {
                    $hostname = strtolower($hostname);
                    Hostname::updateOrCreate(
                        ['hostname' => $hostname],
                        ['server_id' => $server->id, 'last_seen_at' => now()]
                    );
                    $found++;

                    if ($apex = $this->inspector->apexFor($hostname)) {
                        $domain = Domain::firstOrCreate(
                            ['name' => $apex],
                            ['registrar' => 'desconocido', 'source' => 'scan']
                        );
                        if ($domain->wasRecentlyCreated) {
                            $newDomains++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $server->name.': '.$e->getMessage();
            }
        }

        $msg = "Escaneo listo: {$found} subdominios encontrados, {$newDomains} dominios nuevos.";
        if ($errors) {
            $msg .= ' Con errores en: '.implode(' · ', $errors);
        }

        return redirect()->route('dashboard.domains')->with('status', $msg);
    }

    /** Consulta whois y guarda la fecha de vencimiento. */
    public function whois(Domain $domain)
    {
        $result = $this->inspector->whoisExpiry($domain->name);

        if ($result['ok']) {
            $domain->update([
                'expires_at'       => $result['expires_at'],
                'whois_checked_at' => now(),
            ]);

            return redirect()->route('dashboard.domains')
                ->with('status', "✅ {$domain->name} vence el {$result['expires_at']}.");
        }

        return redirect()->route('dashboard.domains')
            ->withErrors(['whois' => "{$domain->name}: {$result['error']}"]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['name'] = strtolower(trim($data['name']));
        $data['source'] = 'manual';

        Domain::firstOrCreate(['name' => $data['name']], $data);

        return redirect()->route('dashboard.domains')->with('status', 'Dominio agregado.');
    }

    public function edit(Domain $domain)
    {
        return view('dashboard.domain-form', compact('domain'));
    }

    public function update(Request $request, Domain $domain)
    {
        $domain->update($this->validated($request, $domain));

        return redirect()->route('dashboard.domains')->with('status', 'Dominio actualizado.');
    }

    public function destroy(Domain $domain)
    {
        $domain->delete();

        return redirect()->route('dashboard.domains')->with('status', 'Dominio eliminado del panel.');
    }

    /** Archiva (inactiva) un dominio — solo si está vencido o cancelado. */
    public function deactivate(Domain $domain)
    {
        if (! $domain->canBeDeactivated()) {
            return redirect()->route('dashboard.domains')
                ->with('error', "«{$domain->name}» está vigente; solo puedes archivar dominios vencidos o cancelados.");
        }

        $domain->update(['is_active' => false]);

        return redirect()->route('dashboard.domains')->with('status', "«{$domain->name}» archivado. Lo ves en el filtro «Inactivos».");
    }

    /** Reactiva un dominio archivado. */
    public function reactivate(Domain $domain)
    {
        $domain->update(['is_active' => true]);

        return redirect()->route('dashboard.domains')->with('status', "«{$domain->name}» reactivado.");
    }

    private function validated(Request $request, ?Domain $domain = null): array
    {
        return $request->validate([
            'name'        => ['required', 'string', 'max:253', Rule::unique('domains', 'name')->ignore($domain?->id)],
            'registrar'   => ['required', Rule::in(['godaddy', 'ionos', 'winhosting', 'otro', 'desconocido'])],
            'expires_at'  => 'nullable|date',
            'renewal_url' => 'nullable|url|max:500',
            'notes'       => 'nullable|string|max:2000',
        ]);
    }
}
