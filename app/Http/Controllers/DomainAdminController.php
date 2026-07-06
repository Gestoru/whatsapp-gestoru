<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Hostname;
use App\Models\Server;
use App\Services\DomainInspector;
use App\Services\ServerMonitor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainAdminController extends Controller
{
    public function __construct(
        private ServerMonitor $monitor,
        private DomainInspector $inspector,
    ) {}

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

        $stats = [
            'total'    => $domains->count(),
            'due'      => $domains->filter(fn ($d) => $d->expiryLevel() === 'due')->count(),
            'warn'     => $domains->filter(fn ($d) => $d->expiryLevel() === 'warn')->count(),
            'unknown'  => $domains->filter(fn ($d) => $d->expiryLevel() === 'unknown')->count(),
        ];

        return view('dashboard.domains', [
            'domains' => $domains,
            'grouped' => $grouped,
            'stats'   => $stats,
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
