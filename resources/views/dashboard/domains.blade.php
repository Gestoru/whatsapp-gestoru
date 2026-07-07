@extends('dashboard.layout')
@section('title', 'Dominios · '.config('dashboard.title'))
@section('subtitle', 'registradores y vencimientos')

@section('actions')
    @if($godaddyConfigured)
        <form method="POST" action="{{ route('dashboard.domains.godaddy.sync') }}" style="display:inline">
            @csrf
            <button class="btn btn-sm" title="Trae el estado de todos tus dominios GoDaddy">🔄 Sincronizar GoDaddy</button>
        </form>
    @endif
    <button class="btn btn-sm" onclick="document.getElementById('godaddy-box').classList.toggle('hidden')">🐦 GoDaddy</button>
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

    {{-- Conexión con GoDaddy --}}
    <div id="godaddy-box" class="card hidden" style="margin-bottom:16px">
        <h2 style="font-size:15px;margin:0 0 6px">🐦 Conectar con GoDaddy</h2>
        <p class="muted tiny" style="margin:0 0 12px">
            Trae automáticamente el estado, vencimiento y auto-renovación de todos tus dominios de GoDaddy.
            @if($godaddyConfigured)
                <span style="color:var(--ok)">✔ Conectado.</span>
                @if($godaddyLastSync) Última sincronización: {{ \Carbon\Carbon::parse($godaddyLastSync)->diffForHumans() }}. @endif
            @endif
        </p>

        <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
            <strong style="color:var(--text)">Cómo obtener tus llaves (1 minuto):</strong>
            <ol style="margin:8px 0 0 18px;padding:0">
                <li>Entra a <span style="font-family:ui-monospace,monospace">developer.godaddy.com/keys</span> (inicia sesión con tu cuenta GoDaddy).</li>
                <li>Crea una API Key de <strong style="color:var(--text)">Producción</strong> ("Production").</li>
                <li>Copia la <strong style="color:var(--text)">Key</strong> y el <strong style="color:var(--text)">Secret</strong> y pégalos aquí abajo.</li>
            </ol>
        </div>

        <form method="POST" action="{{ route('dashboard.domains.godaddy.connect') }}">
            @csrf
            <div class="form-grid">
                <div class="field"><label>API Key</label><input name="godaddy_api_key" value="{{ $godaddyKey }}" placeholder="dLD..." required></div>
                <div class="field"><label>API Secret @if($godaddyConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label><input name="godaddy_api_secret" type="password" autocomplete="new-password" placeholder="••••••••"></div>
            </div>
            <div class="row" style="justify-content:flex-end;gap:8px">
                <button class="btn btn-primary btn-sm">Guardar credenciales</button>
                @if($godaddyConfigured)
                    <button formaction="{{ route('dashboard.domains.godaddy.sync') }}" class="btn btn-sm">🔄 Sincronizar ahora</button>
                @endif
            </div>
        </form>
        <p class="muted tiny" style="margin-top:8px">🔒 El secreto se guarda cifrado. Los dominios se sincronizan solos cada pocas horas.</p>
    </div>

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
                <thead><tr><th>Dominio</th><th>Registrador</th><th>Estado</th><th>Auto-renueva</th><th>Vence</th><th>Vencimiento</th><th>Subdominios</th><th></th></tr></thead>
                <tbody>
                @foreach($domains as $domain)
                    @php($lvl = $domain->expiryLevel())
                    @php($days = $domain->daysLeft())
                    @php($tone = ['due'=>'#fca5a5','warn'=>'#fcd34d','ok'=>'#86efac','unknown'=>'var(--muted)'][$lvl])
                    @php($stTone = ['ok'=>'#86efac','warn'=>'#fcd34d','bad'=>'#fca5a5','muted'=>'var(--muted)'][$domain->statusTone()])
                    @php($subs = $grouped[$domain->name] ?? [])
                    <tr>
                        <td style="font-weight:700">🌐 {{ $domain->name }}</td>
                        <td><span class="pill tiny">{{ $domain->registrar_label }}</span></td>
                        <td>
                            @if($domain->status_label)
                                <span style="color:{{ $stTone }};font-weight:600">{{ $domain->status_label }}</span>
                            @else
                                <span class="muted tiny">—</span>
                            @endif
                        </td>
                        <td>
                            @if($domain->auto_renew === true)<span style="color:var(--ok)">✔ sí</span>
                            @elseif($domain->auto_renew === false)<span style="color:var(--warn)">✘ no</span>
                            @else<span class="muted tiny">—</span>@endif
                        </td>
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
                            <td colspan="8" style="background:#0e1630">
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
