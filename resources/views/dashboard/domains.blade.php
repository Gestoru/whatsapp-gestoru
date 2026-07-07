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

    {{-- Resumen (clic para filtrar) --}}
    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat filt" data-filter="all" style="cursor:pointer"><div class="k">Todos los dominios</div><div class="v">{{ $stats['total'] }}</div></div>
        <div class="stat filt" data-filter="due" style="cursor:pointer;border-color:#5b2330"><div class="k">Por vencer (≤10 días)</div><div class="v" style="color:#fca5a5">{{ $stats['due'] }}</div></div>
        <div class="stat filt" data-filter="warn" style="cursor:pointer;border-color:#6b5316"><div class="k">Próximos (≤30 días)</div><div class="v" style="color:#fcd34d">{{ $stats['warn'] }}</div></div>
        <div class="stat filt" data-filter="unknown" style="cursor:pointer"><div class="k">Sin fecha</div><div class="v muted">{{ $stats['unknown'] }}</div></div>
        <div class="stat filt" data-filter="inactive" style="cursor:pointer"><div class="k">Inactivos (archivados)</div><div class="v muted">{{ $stats['inactive'] }}</div></div>
    </div>
    <div id="filter-note" class="tiny muted hidden" style="margin:-8px 0 12px">
        Mostrando solo: <strong id="filter-label"></strong> · <a href="#" id="filter-clear" style="color:var(--accent)">ver todos</a>
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
                    <tr class="dom-row" data-level="{{ $lvl }}" data-active="{{ $domain->is_active ? 1 : 0 }}" @if(count($subs)) data-toggle="{{ $domain->id }}" style="cursor:pointer" @endif>
                        <td style="font-weight:700">
                            @if(count($subs))
                                <span class="chev" id="chev-{{ $domain->id }}" style="display:inline-block;width:12px;color:var(--muted);transition:transform .2s">▸</span>
                            @else
                                <span style="display:inline-block;width:12px"></span>
                            @endif
                            🌐 {{ $domain->name }}
                        </td>
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
                                <span class="pill tiny" style="background:#2b2450;color:#b3a4f5">{{ count($subs) }} subdominios</span>
                            @else
                                <span class="muted tiny">—</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap" onclick="event.stopPropagation()">
                            <form method="POST" action="{{ route('dashboard.domains.whois', $domain) }}" style="display:inline">
                                @csrf
                                <button class="btn btn-ghost btn-sm" title="Consultar vencimiento por whois">🔎 whois</button>
                            </form>
                            @if(in_array($lvl,['due','warn']) && $domain->renewalLink())
                                <a href="{{ $domain->renewalLink() }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">💳 Renovar</a>
                            @endif
                            @if(! $domain->is_active)
                                <form method="POST" action="{{ route('dashboard.domains.reactivate', $domain) }}" style="display:inline">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm" title="Volver a administrar este dominio">♻️ Reactivar</button>
                                </form>
                            @elseif($domain->canBeDeactivated())
                                <form method="POST" action="{{ route('dashboard.domains.deactivate', $domain) }}" style="display:inline"
                                      onsubmit="return confirm('Archivar «{{ $domain->name }}»? Saldrá de la vista principal y quedará en el filtro «Inactivos». Podrás reactivarlo cuando quieras.')">
                                    @csrf
                                    <button class="btn btn-ghost btn-sm" title="Archivar (vencido/cancelado)">🗄️ Archivar</button>
                                </form>
                            @endif
                            <a href="{{ route('dashboard.domains.edit', $domain) }}" class="btn btn-ghost btn-sm">✏️</a>
                        </td>
                    </tr>
                    @if(count($subs))
                        <tr id="subs-{{ $domain->id }}" class="sub-row hidden" data-parent="{{ $domain->id }}" data-level="{{ $lvl }}" data-active="{{ $domain->is_active ? 1 : 0 }}">
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
<style>
    .hidden{display:none}
    .filt-hide{display:none !important}
    .stat.filt.active{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent)}
    .dom-row:hover td{background:#161f3b}
</style>
<script>
// ── Desplegable de subdominios ──
document.querySelectorAll('.dom-row[data-toggle]').forEach(row => {
    row.addEventListener('click', () => {
        const id = row.dataset.toggle;
        const sub = document.getElementById('subs-' + id);
        const chev = document.getElementById('chev-' + id);
        if (!sub) return;
        const open = sub.classList.toggle('hidden');
        if (chev) chev.style.transform = open ? '' : 'rotate(90deg)';
    });
});

// ── Filtro por estado de vencimiento / archivado ──
const labels = {all:'activos', due:'por vencer (≤10 días)', warn:'próximos (≤30 días)', unknown:'sin fecha', inactive:'inactivos (archivados)'};
// Un dominio se muestra si pasa el filtro. Por defecto (cualquier filtro que
// no sea "inactive") solo se ven los ACTIVOS; "inactive" muestra los archivados.
function rowVisible(r, f){
    const active = r.dataset.active === '1';
    if (f === 'inactive') return !active;
    if (!active) return false;                 // los archivados no salen salvo en "inactive"
    if (f === 'all') return true;
    return r.dataset.level === f;
}
function applyFilter(f){
    document.querySelectorAll('.dom-row').forEach(r => r.classList.toggle('filt-hide', !rowVisible(r, f)));
    document.querySelectorAll('.sub-row').forEach(r => r.classList.toggle('filt-hide', !rowVisible(r, f)));
    document.querySelectorAll('.stat.filt').forEach(s => s.classList.toggle('active', s.dataset.filter === f && f !== 'all'));
    const note = document.getElementById('filter-note');
    if (f === 'all') { note.classList.add('hidden'); }
    else { note.classList.remove('hidden'); document.getElementById('filter-label').textContent = labels[f] || f; }
}
document.querySelectorAll('.stat.filt').forEach(s => {
    s.addEventListener('click', () => applyFilter(s.dataset.filter));
});
document.getElementById('filter-clear')?.addEventListener('click', e => { e.preventDefault(); applyFilter('all'); });
// Al cargar: ocultar los archivados (mostrar solo activos)
applyFilter('all');
</script>
@endpush
