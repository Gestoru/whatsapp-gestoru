@extends('dashboard.layout')
@section('title', 'Dominios · '.config('dashboard.title'))
@section('subtitle', 'registradores y vencimientos')

@section('actions')
    <form method="POST" action="{{ route('dashboard.domains.scan') }}" style="display:inline">
        @csrf
        <button class="btn btn-sm" title="Busca dominios y subdominios en todos los servidores">🔍 Escanear servidores</button>
    </form>
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('add-domain').classList.toggle('hidden')">＋ Agregar dominio</button>
@endsection

@section('content')
    @if($errors->any())
        <div class="alert alert-bad">{{ $errors->first() }}</div>
    @endif

    {{-- Resumen --}}
    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat"><div class="k">Dominios</div><div class="v">{{ $stats['total'] }}</div></div>
        <div class="stat" style="border-color:#5b2330"><div class="k">Por vencer (≤10 días)</div><div class="v" style="color:#fca5a5">{{ $stats['due'] }}</div></div>
        <div class="stat" style="border-color:#6b5316"><div class="k">Próximos (≤30 días)</div><div class="v" style="color:#fcd34d">{{ $stats['warn'] }}</div></div>
        <div class="stat"><div class="k">Sin fecha</div><div class="v muted">{{ $stats['unknown'] }}</div></div>
    </div>

    {{-- Alta rápida --}}
    <div id="add-domain" class="card hidden" style="margin-bottom:16px">
        <form method="POST" action="{{ route('dashboard.domains.store') }}">
            @csrf
            <div class="form-grid">
                <div class="field"><label>Dominio</label><input name="name" placeholder="gestoru.com" required></div>
                <div class="field"><label>Registrador</label>
                    <select name="registrar">
                        @foreach(['godaddy'=>'GoDaddy','ionos'=>'IONOS','winhosting'=>'Winhosting','otro'=>'Otro','desconocido'=>'Desconocido'] as $v=>$l)
                            <option value="{{ $v }}">{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="form-grid">
                <div class="field"><label>Vence el (opcional)</label><input name="expires_at" type="date"></div>
                <div class="field"><label>Enlace de renovación (opcional)</label><input name="renewal_url" type="url" placeholder="https://…"></div>
            </div>
            <div class="row" style="justify-content:flex-end"><button class="btn btn-primary btn-sm">Guardar dominio</button></div>
        </form>
    </div>

    @if($domains->isEmpty())
        <div class="card empty">
            <div class="big">🌐</div>
            <h1>Aún no hay dominios</h1>
            <p class="muted" style="margin:10px 0 20px">Presiona «Escanear servidores» para detectarlos automáticamente, o agrégalos a mano.</p>
        </div>
    @else
        <div class="list-card">
            <table>
                <thead><tr><th>Dominio</th><th>Registrador</th><th>Vence</th><th>Estado</th><th>Subdominios</th><th></th></tr></thead>
                <tbody>
                @foreach($domains as $domain)
                    @php($lvl = $domain->expiryLevel())
                    @php($days = $domain->daysLeft())
                    @php($tone = ['due'=>'#fca5a5','warn'=>'#fcd34d','ok'=>'#86efac','unknown'=>'var(--muted)'][$lvl])
                    @php($subs = $grouped[$domain->name] ?? [])
                    <tr>
                        <td style="font-weight:700">🌐 {{ $domain->name }}</td>
                        <td><span class="pill tiny">{{ $domain->registrar_label }}</span></td>
                        <td>{{ $domain->expires_at ? $domain->expires_at->format('d/m/Y') : '—' }}</td>
                        <td>
                            <span style="color:{{ $tone }};font-weight:600">
                                @if($lvl==='unknown') sin fecha
                                @elseif($days < 0) vencido
                                @elseif($days === 0) vence hoy
                                @else {{ $days }} días @endif
                            </span>
                        </td>
                        <td>
                            @if(count($subs))
                                <button class="btn btn-ghost btn-sm" onclick="document.getElementById('subs-{{ $domain->id }}').classList.toggle('hidden')">
                                    {{ count($subs) }} ▾
                                </button>
                            @else
                                <span class="muted tiny">—</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <form method="POST" action="{{ route('dashboard.domains.whois', $domain) }}" style="display:inline">
                                @csrf
                                <button class="btn btn-ghost btn-sm" title="Consultar vencimiento por whois">🔎 whois</button>
                            </form>
                            @if(in_array($lvl,['due','warn']) && $domain->renewalLink())
                                <a href="{{ $domain->renewalLink() }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">💳 Renovar</a>
                            @endif
                            <a href="{{ route('dashboard.domains.edit', $domain) }}" class="btn btn-ghost btn-sm">✏️</a>
                        </td>
                    </tr>
                    @if(count($subs))
                        <tr id="subs-{{ $domain->id }}" class="hidden">
                            <td colspan="6" style="background:#0e1630">
                                <div style="padding:4px 6px">
                                    @foreach($subs as $h)
                                        <div class="row" style="justify-content:space-between;padding:5px 8px;border-bottom:1px solid #1a2340">
                                            <span style="font-family:ui-monospace,monospace;font-size:13px">↳ {{ $h->hostname }}</span>
                                            <span class="muted tiny">
                                                @if($h->server)
                                                    <a href="{{ route('dashboard.servers.domain', $h->server) }}?d={{ urlencode($h->hostname) }}" style="color:var(--accent)">
                                                        {{ $h->server->name }} · ver reporte →
                                                    </a>
                                                @endif
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted tiny" style="margin-top:12px">💡 «whois» consulta la fecha real de vencimiento de cada dominio. Para GoDaddy, IONOS y Winhosting funciona con el nombre del dominio raíz.</p>
    @endif
@endsection

@push('scripts')
<style>.hidden{display:none}</style>
@endpush
