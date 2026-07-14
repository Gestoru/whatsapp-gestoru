@extends('dashboard.layout')
@section('title', $server->name.' · '.config('dashboard.title'))
@section('subtitle', $server->provider_label.' · '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.index') }}" class="btn btn-ghost btn-sm">← Servidores</a>
    <a href="{{ route('dashboard.servers.trends', $server) }}" class="btn btn-primary btn-sm">📊 Tendencias y análisis</a>
    <details class="act-menu" id="actMenu">
        <summary class="btn btn-sm">⋯ Más acciones</summary>
        <div class="act-menu-list">
            <a href="{{ route('dashboard.servers.live', $server) }}">👥 Usuarios en vivo</a>
            <a href="{{ route('dashboard.servers.stress', $server) }}">🧪 Prueba de estrés</a>
            <button type="button" id="btn-test" data-url="{{ route('dashboard.servers.test', $server) }}">🔌 Probar conexión</button>
            <div class="act-menu-sep"></div>
            <a href="{{ route('dashboard.servers.edit', $server) }}">✏️ Editar servidor</a>
        </div>
    </details>
@endsection

@section('content')
    <div class="row" style="justify-content:space-between;margin-bottom:6px">
        <h1>{{ $server->name }}</h1>
        @if($needsCredentials)
            <span class="pill"><span class="dot" style="background:var(--warn);box-shadow:0 0 8px var(--warn)"></span>Falta contraseña</span>
        @else
            <span class="pill"><span class="dot {{ $error ? 'bad':'ok' }}"></span>{{ $error ? 'Sin conexión' : 'En línea' }}</span>
        @endif
    </div>
    @if($server->notes)<p class="muted tiny" style="margin:0 0 8px">{{ $server->notes }}</p>@endif

    {{-- ── Plan y pago ── --}}
    @php($plevel = $server->paymentLevel())
    @php($pdays = $server->paymentDaysLeft())
    @php($ptone = ['due'=>['#2a1116','#5b2330','#fca5a5'],'warn'=>['#2e2410','#6b5316','#fcd34d'],'ok'=>['#0f2a1a','#1e5637','#86efac'],'unknown'=>['#101a33','var(--line)','var(--muted)']][$plevel])
    <div class="card" style="margin:8px 0 4px;padding:14px 16px;background:{{ $ptone[0] }};border-color:{{ $ptone[1] }}">
        <div class="row" style="justify-content:space-between;gap:12px">
            <div class="row" style="gap:20px">
                <div>
                    <div class="k muted tiny">Plan vigente hasta</div>
                    <div style="font-size:18px;font-weight:700;color:{{ $ptone[2] }}">
                        {{ $server->paid_until ? $server->paid_until->format('d/m/Y') : 'Sin definir' }}
                    </div>
                    @if($pdays !== null)
                        <div class="tiny" style="color:{{ $ptone[2] }}">
                            @if($pdays < 0) venció hace {{ abs($pdays) }} días
                            @elseif($pdays === 0) vence hoy
                            @else faltan {{ $pdays }} días @endif
                        </div>
                    @endif
                </div>
                @if($server->monthly_cost)
                    <div>
                        <div class="k muted tiny">Costo mensual</div>
                        <div style="font-size:18px;font-weight:700">US$ {{ number_format($server->monthly_cost, 2) }}</div>
                    </div>
                @endif
            </div>
            <div class="row">
                @if(in_array($plevel, ['due','warn']) && $server->renewalLink())
                    <a href="{{ $server->renewalLink() }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">💳 Pagar / renovar</a>
                @elseif($server->renewalLink())
                    <a href="{{ $server->renewalLink() }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Ver facturación</a>
                @endif
                <a href="{{ route('dashboard.servers.edit', $server) }}#plan" class="btn btn-ghost btn-sm">✏️ Editar plan</a>
            </div>
        </div>
    </div>

    {{-- ── Control del VPS por API (Contabo) ── --}}
    @if($server->provider_instance_id)
        @php($ps = strtolower((string) $server->provider_status))
        <div class="card" style="margin:8px 0 4px;padding:12px 16px">
            <div class="row" style="justify-content:space-between;gap:12px;flex-wrap:wrap">
                <div class="row" style="gap:14px">
                    <span class="pill">🟠 Contabo
                        @if(str_contains($ps,'running'))<span class="dot ok" style="margin-left:6px"></span> encendido
                        @elseif(str_contains($ps,'stopped'))<span class="dot bad" style="margin-left:6px"></span> apagado
                        @elseif($ps)· {{ $ps }}@endif
                    </span>
                    @if($server->provider_product)<span class="muted tiny">Plan: {{ $server->provider_product }}</span>@endif
                    @if($server->provider_region)<span class="muted tiny">· {{ $server->provider_region }}</span>@endif
                </div>
                <div class="row" style="gap:6px">
                    @foreach([['start','▶️ Encender','¿Encender este servidor?'],['restart','🔄 Reiniciar','¿Reiniciar este servidor? Se cortará el servicio unos segundos.'],['stop','⏹️ Apagar','¿APAGAR este servidor? Dejará de responder hasta que lo enciendas.']] as [$act,$label,$confirm])
                        <form method="POST" action="{{ route('dashboard.contabo.action', [$server, $act]) }}" style="display:inline"
                              onsubmit="return confirm('{{ $confirm }}')">
                            @csrf
                            <button class="btn btn-sm {{ $act==='stop'?'btn-danger':'' }}">{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div id="test-result"></div>

    @if($needsCredentials)
        <div class="alert" style="background:#2e2410;border-color:#6b5316;color:#fcd34d">
            <strong>🔑 Este servidor está pre-cargado pero aún no tiene su contraseña.</strong><br>
            <span class="tiny">Ponle la contraseña root de este servidor para empezar a ver su rendimiento, proyectos y archivos.</span>
        </div>
        <a href="{{ route('dashboard.servers.edit', $server) }}" class="btn btn-primary">🔑 Poner contraseña ahora</a>
    @elseif($error)
        <div class="alert alert-bad">
            <strong>No se pudo conectar al servidor.</strong><br>
            <span class="tiny">{{ $error }}</span><br>
            <span class="tiny muted">Revisa la IP/host, el puerto, el usuario y la contraseña o llave en <a href="{{ route('dashboard.servers.edit',$server) }}" style="color:var(--accent)">Editar</a>.</span>
        </div>
    @else
        {{-- ── Rendimiento ── --}}
        <h2><span class="section-ic">📊</span> Rendimiento</h2>
        <div class="grid" style="grid-template-columns:1.4fr 1fr;gap:16px" id="perf-grid">
            <div class="card">
                @foreach([['cpu','CPU'],['mem','Memoria RAM'],['disk','Disco']] as [$k,$label])
                    <div style="margin-bottom:16px">
                        <div class="metric-label">
                            <span style="font-weight:600">{{ $label }}</span>
                            <span class="js-{{ $k }}-txt muted">—</span>
                        </div>
                        <div class="meter" style="height:12px"><span class="js-{{ $k }}-bar" style="width:0%"></span></div>
                    </div>
                @endforeach
            </div>
            <div class="stat-grid" style="align-content:start">
                <div class="stat"><div class="k">Sistema</div><div class="v js-os" style="font-size:14px">—</div></div>
                <div class="stat"><div class="k">Uptime</div><div class="v js-uptime" style="font-size:16px">—</div></div>
                <div class="stat"><div class="k">Carga (1/5/15m)</div><div class="v js-load" style="font-size:15px">—</div></div>
                <div class="stat"><div class="k">Núcleos CPU</div><div class="v js-cores">—</div></div>
                <a href="{{ route('dashboard.servers.live', $server) }}" class="stat link" style="text-decoration:none;border-color:#2f3d63">
                    <div class="k">👥 Usuarios activos</div>
                    <div class="v js-users" style="font-size:18px">—</div>
                    <div class="tiny" style="color:var(--accent)">ver en vivo →</div>
                </a>
            </div>
        </div>

        {{-- ── Proyectos ── --}}
        <h2><span class="section-ic">📦</span> Proyectos y servicios <span class="muted tiny" style="font-weight:400">({{ count($projects) }})</span></h2>
        <div class="list-card">
            @if(empty($projects))
                <div class="empty" style="padding:30px"><span class="muted">No se detectaron proyectos, contenedores ni servicios conocidos.</span></div>
            @else
                <table>
                    <thead><tr><th>Tipo</th><th>Nombre</th><th>Detalle</th><th>Dominios</th></tr></thead>
                    <tbody>
                    @foreach($projects as $p)
                        <tr>
                            <td><span class="tag tag-{{ $p['type'] }}">{{ $p['type'] }}</span></td>
                            <td style="font-weight:600">{{ $p['name'] }}</td>
                            <td class="muted tiny" style="font-family:ui-monospace,monospace">{{ $p['detail'] }}</td>
                            <td>
                                @forelse($p['domains'] ?? [] as $pd)
                                    <a href="{{ route('dashboard.servers.domain', $server) }}?d={{ urlencode($pd) }}"
                                       class="pill tiny" style="margin:2px 3px 2px 0;font-family:ui-monospace,monospace">🌐 {{ $pd }}</a>
                                @empty
                                    <span class="muted tiny">—</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Dominios ── --}}
        <h2><span class="section-ic">🌐</span> Dominios alojados
            <span class="muted tiny" style="font-weight:400">({{ count($domains) }} en {{ count($domainGroups) }} dominios raíz) · clic en un subdominio para su reporte</span>
        </h2>
        @if(empty($domains))
            <div class="list-card" style="padding:14px"><span class="muted tiny">No se detectaron dominios reales en la configuración de nginx/apache/Docker de este servidor.</span></div>
        @else
            <div class="row" style="gap:14px;margin-bottom:10px;flex-wrap:wrap">
                <span class="tiny muted">🟢 servidor web</span>
                <span class="tiny muted">🔒 certificado SSL activo</span>
                <span class="tiny muted">🐳 contenedor</span>
                <span class="tiny muted" style="margin-left:auto">solo se listan dominios realmente alojados aquí (se filtran servicios externos y archivos)</span>
            </div>
            <div class="row" style="justify-content:space-between;margin-bottom:10px;gap:8px">
                <input type="text" id="dom-filter" placeholder="🔎 Buscar dominio o subdominio…" autocomplete="off" style="max-width:340px">
                <div class="row" style="gap:6px">
                    <button type="button" class="btn btn-ghost btn-sm" id="dom-expand">Expandir todo</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="dom-collapse">Colapsar todo</button>
                </div>
            </div>
            <div id="dom-groups" class="grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:12px">
                @foreach($domainGroups as $apex => $subs)
                    <div class="list-card dom-group" data-names="{{ strtolower(implode(' ', $subs)) }}">
                        <div class="dom-group-head" style="display:flex;align-items:center;gap:8px;padding:11px 14px;cursor:pointer;background:var(--card2);border-bottom:1px solid var(--line)">
                            <span class="dg-chev" style="display:inline-block;width:12px;color:var(--muted);transition:transform .2s">▸</span>
                            <span style="font-weight:700">🌐 {{ $apex }}</span>
                            <button type="button" class="dom-restart" data-apex="{{ $apex }}" title="Reiniciar el servicio (contenedores Docker) de {{ $apex }}"
                                style="margin-left:auto;background:none;border:1px solid var(--line);color:var(--muted);border-radius:7px;cursor:pointer;font-size:11px;padding:3px 8px">🔄 Reiniciar</button>
                            <span class="pill tiny">{{ count($subs) }}</span>
                        </div>
                        <div class="dom-group-body hidden" style="padding:10px 12px">
                            @foreach($subs as $d)
                                @php($meta = $domainMeta[$d] ?? ['icons' => '', 'projects' => []])
                                <a href="{{ route('dashboard.servers.domain', $server) }}?d={{ urlencode($d) }}"
                                   class="dom-sub" data-name="{{ strtolower($d) }}"
                                   style="display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:8px;font-size:13px;font-family:ui-monospace,monospace">
                                    <span>{{ $d === $apex ? '🌐' : '↳' }}</span>
                                    <span>{{ $d }}</span>
                                    @if($meta['icons'])<span title="dónde se encontró">{{ $meta['icons'] }}</span>@endif
                                    @foreach($meta['projects'] as $proj)
                                        <span class="tag tag-docker" style="font-size:10px">{{ $proj }}</span>
                                    @endforeach
                                    <span class="muted" style="margin-left:auto">ver reporte →</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div id="dom-empty" class="muted tiny hidden" style="margin-top:10px">Sin coincidencias.</div>
        @endif

        {{-- ── Procesos que más consumen ── --}}
        <h2><span class="section-ic">🔥</span> Procesos que más consumen</h2>
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:16px">
            @foreach([['cpu','Por CPU'],['mem','Por memoria']] as [$key,$label])
                <div class="list-card">
                    <div class="fb-head">{{ $label }}</div>
                    @if(empty($processes[$key]))
                        <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
                    @else
                        <table>
                            <thead><tr><th>%CPU</th><th>%MEM</th><th>Proceso</th></tr></thead>
                            <tbody>
                            @foreach($processes[$key] as $p)
                                <tr>
                                    <td style="font-weight:600">{{ $p['cpu'] }}</td>
                                    <td style="font-weight:600">{{ $p['mem'] }}</td>
                                    <td class="muted tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $p['command'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- ── Consultas SQL lentas ── --}}
        <h2><span class="section-ic">🐢</span> Consultas SQL lentas (MySQL/MariaDB)</h2>
        <div class="list-card fb-file">
            @if(! $slow['enabled'])
                <div style="padding:16px">
                    <span class="muted tiny">El registro de consultas lentas (slow query log) no está activo en este servidor.
                    Cuando decidamos activarlo (Fase 2), aquí aparecerán las consultas que más demoran, listas para optimizar.</span>
                </div>
            @elseif(empty($slow['top']))
                <div class="empty" style="padding:24px"><span class="muted tiny">Slow log activo ({{ $slow['file'] }}) y sin consultas lentas registradas 🎉</span></div>
            @else
                <div class="fb-head">Top consultas más lentas · {{ $slow['file'] }}</div>
                <pre style="max-height:300px">{{ implode("\n", $slow['top']) }}</pre>
            @endif
        </div>

        {{-- ── Explorador de archivos ── --}}
        <h2><span class="section-ic">📁</span> Explorador de archivos</h2>
        <div class="fb">
            <div class="fb-pane">
                <div class="fb-head" id="fb-crumbs"><span class="crumb" data-path="/">/</span></div>
                <div class="fb-list" id="fb-list"><div style="padding:20px" class="muted tiny">Cargando…</div></div>
            </div>
            <div class="fb-pane fb-file">
                <div class="fb-head" id="fb-filehead"><span class="muted tiny">Selecciona un archivo para ver su contenido</span></div>
                <div id="fb-fileview"><div class="empty" style="padding:40px"><span class="muted tiny">Ningún archivo abierto</span></div></div>
            </div>
        </div>
    @endif

    <div style="margin-top:34px">
        <form method="POST" action="{{ route('dashboard.servers.destroy', $server) }}"
              onsubmit="return confirm('¿Eliminar este servidor del panel? (no afecta al servidor real)')">
            @csrf @method('DELETE')
            <button class="btn btn-danger btn-sm">🗑️ Eliminar del panel</button>
        </form>
    </div>
@endsection

@push('scripts')
<style>
    /* Menú "⋯ Más acciones" del encabezado */
    .act-menu{position:relative}
    .act-menu>summary{list-style:none;cursor:pointer}
    .act-menu>summary::-webkit-details-marker{display:none}
    .act-menu[open]>summary{border-color:var(--accent);background:#202c52}
    .act-menu-list{position:absolute;right:0;top:calc(100% + 6px);min-width:210px;z-index:60;
        background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:6px;
        box-shadow:0 16px 40px rgba(0,0,0,.5);display:flex;flex-direction:column;gap:2px}
    .act-menu-list a,.act-menu-list button{display:flex;align-items:center;gap:9px;width:100%;text-align:left;
        padding:9px 11px;border-radius:8px;background:transparent;border:0;color:var(--text);
        font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
    .act-menu-list a:hover,.act-menu-list button:hover{background:#202c52}
    .act-menu-sep{height:1px;background:var(--line);margin:4px 2px}
    .dom-sub:hover{background:var(--card2)}
    .hidden{display:none}
</style>
<script>
// ── Organización de dominios (grupos + buscador) ──
document.querySelectorAll('.dom-group-head').forEach(h => {
    h.addEventListener('click', () => {
        const body = h.nextElementSibling;
        const chev = h.querySelector('.dg-chev');
        const open = body.classList.toggle('hidden');
        chev.style.transform = open ? '' : 'rotate(90deg)';
    });
});
function setAll(collapsed){
    document.querySelectorAll('.dom-group').forEach(g => {
        g.querySelector('.dom-group-body').classList.toggle('hidden', collapsed);
        g.querySelector('.dg-chev').style.transform = collapsed ? '' : 'rotate(90deg)';
    });
}
document.getElementById('dom-expand')?.addEventListener('click', () => setAll(false));
document.getElementById('dom-collapse')?.addEventListener('click', () => setAll(true));

const domFilter = document.getElementById('dom-filter');
domFilter?.addEventListener('input', () => {
    const q = domFilter.value.toLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('.dom-group').forEach(g => {
        let anyMatch = false;
        g.querySelectorAll('.dom-sub').forEach(s => {
            const m = s.dataset.name.includes(q);
            s.classList.toggle('hidden', q !== '' && !m);
            if (m) anyMatch = true;
        });
        const matchGroup = q === '' || anyMatch;
        g.classList.toggle('hidden', !matchGroup);
        if (matchGroup) shown++;
        // al buscar, abrir los grupos con coincidencias
        if (q !== '' && anyMatch) { g.querySelector('.dom-group-body').classList.remove('hidden'); g.querySelector('.dg-chev').style.transform='rotate(90deg)'; }
    });
    const empty = document.getElementById('dom-empty');
    if (empty) empty.classList.toggle('hidden', shown !== 0);
});

const CSRF = document.querySelector('meta[name=csrf-token]').content;

// ── Reiniciar el servicio (contenedores Docker) de un dominio ──
const RESTART_URL = @json(route('dashboard.servers.service.restart', $server));
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.dom-restart');
    if(!btn) return;
    e.preventDefault(); e.stopPropagation();
    const apex = btn.dataset.apex;
    if(!confirm('¿Reiniciar el servicio de ' + apex + '?\n\nSe reiniciarán sus contenedores Docker. El sitio quedará indisponible unos segundos mientras arranca de nuevo.')) return;
    const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '⏳ Reiniciando…';
    try{
        const r = await fetch(RESTART_URL, {method:'POST',
            headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json',Accept:'application/json'},
            body: JSON.stringify({project: apex})});
        const d = await r.json();
        if(d.ok){
            btn.innerHTML = '✅ Reiniciado';
            alert('✅ Reiniciado: ' + d.restarted.join(', ') + '\n\nDale unos 10-20 segundos y recarga el sitio.');
        } else {
            btn.innerHTML = '⚠️ Falló';
            alert('No se pudo reiniciar:\n' + (d.error || 'error desconocido'));
        }
    }catch(err){ btn.innerHTML = '⚠️ Error'; alert('Error de red: ' + err.message); }
    setTimeout(() => { btn.disabled = false; btn.innerHTML = old; }, 4000);
});

const barColor = p => p >= 90 ? 'var(--bad)' : p >= 70 ? 'var(--warn)' : 'var(--ok)';
const j = (el,html)=>el.innerHTML=html;

@if(!$error && !$needsCredentials)
// ── Métricas iniciales (render server-side friendly): pintamos con los datos ya cargados
const M = @json($metrics);
function paint(){
    const set=(k,pct,txt)=>{
        const bar=document.querySelector('.js-'+k+'-bar'), t=document.querySelector('.js-'+k+'-txt');
        if(pct==null){t.textContent='—';return;}
        bar.style.width=pct+'%'; bar.style.background=barColor(pct); t.classList.remove('muted'); t.textContent=txt;
    };
    set('cpu', M.cpu_pct, (M.cpu_pct??'—')+(M.cpu_pct!=null?'%':''));
    set('mem', M.mem.pct, M.mem.used+' / '+M.mem.total+' ('+M.mem.pct+'%)');
    set('disk', M.disk.pct, M.disk.used+' / '+M.disk.total+' ('+M.disk.pct+'%)');
    document.querySelector('.js-os').textContent = M.os;
    document.querySelector('.js-uptime').textContent = M.uptime;
    document.querySelector('.js-load').textContent = M.load;
    document.querySelector('.js-cores').textContent = M.cpu_cores;
    const u = document.querySelector('.js-users'); if(u) u.textContent = (M.web_users ?? '—');
}
paint();
// refresco cada 15s
setInterval(async()=>{
    try{
        const r=await fetch('{{ route('dashboard.servers.metrics',$server) }}',{headers:{Accept:'application/json'}});
        const d=await r.json(); if(d.ok){ Object.assign(M,d.metrics); paint(); }
    }catch(e){}
}, 15000);

// ── Explorador de archivos ──
const filesUrl='{{ route('dashboard.servers.files',$server) }}';
const fileUrl='{{ route('dashboard.servers.file',$server) }}';
const icon = e => e.is_link?'🔗':e.is_dir?'📁':/\.(png|jpe?g|gif|svg|webp|ico)$/i.test(e.name)?'🖼️':
    /\.(zip|tar|gz|rar|7z)$/i.test(e.name)?'🗜️':/\.(js|ts|php|py|sh|json|env|conf|yml|yaml|md|txt|log|html|css)$/i.test(e.name)?'📄':'📄';

function crumbs(path){
    const parts = path.split('/').filter(Boolean);
    let acc='', html='<span class="crumb" data-path="/">/</span>';
    parts.forEach(p=>{ acc+='/'+p; html+='<span class="muted">&nbsp;/&nbsp;</span><span class="crumb" data-path="'+acc+'">'+p+'</span>'; });
    j(document.getElementById('fb-crumbs'), html);
    document.querySelectorAll('#fb-crumbs .crumb').forEach(c=>c.onclick=()=>browse(c.dataset.path));
}

async function browse(path){
    const list=document.getElementById('fb-list');
    j(list,'<div style="padding:20px"><span class="spin"></span></div>');
    crumbs(path);
    try{
        const r=await fetch(filesUrl+'?path='+encodeURIComponent(path),{headers:{Accept:'application/json'}});
        const d=await r.json();
        if(!d.ok){ j(list,'<div style="padding:16px" class="alert alert-bad">'+d.error+'</div>'); return; }
        if(!d.entries.length){ j(list,'<div class="empty" style="padding:30px"><span class="muted tiny">Carpeta vacía o sin permisos</span></div>'); return; }
        list.innerHTML='';
        d.entries.forEach(e=>{
            const full = (d.path==='/'?'':d.path)+'/'+e.name;
            const row=document.createElement('div'); row.className='fb-item';
            row.innerHTML='<span class="ic">'+icon(e)+'</span><span>'+e.name+'</span>'+
                '<span class="meta">'+(e.is_dir?'':e.size+' · ')+e.modified+'</span>';
            row.onclick=()=> e.is_dir ? browse(full) : openFile(full);
            list.appendChild(row);
        });
    }catch(e){ j(list,'<div style="padding:16px" class="alert alert-bad">Error cargando el directorio</div>'); }
}

async function openFile(path){
    const head=document.getElementById('fb-filehead'), view=document.getElementById('fb-fileview');
    j(head,'<span class="spin"></span>&nbsp; '+path);
    j(view,'<div style="padding:20px"><span class="spin"></span></div>');
    try{
        const r=await fetch(fileUrl+'?path='+encodeURIComponent(path),{headers:{Accept:'application/json'}});
        const d=await r.json();
        if(!d.ok){ j(view,'<div style="padding:16px" class="alert alert-bad">'+d.error+'</div>'); return; }
        j(head,'<span>📄 '+path+'</span><span class="meta" style="margin-left:auto">'+d.size+(d.truncated?' · truncado':'')+'</span>');
        if(d.binary){ j(view,'<div class="empty" style="padding:40px"><span class="muted tiny">Archivo binario — no se puede mostrar como texto</span></div>'); return; }
        const esc = d.content.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        j(view,'<pre>'+esc+'</pre>');
    }catch(e){ j(view,'<div style="padding:16px" class="alert alert-bad">Error leyendo el archivo</div>'); }
}
browse('/');
@endif

// ── Probar conexión ──
document.getElementById('btn-test')?.addEventListener('click', async (ev)=>{
    const btn=ev.currentTarget, box=document.getElementById('test-result');
    btn.disabled=true; const old=btn.textContent; btn.innerHTML='<span class="spin"></span> Probando…';
    try{
        const r=await fetch(btn.dataset.url,{method:'POST',headers:{'X-CSRF-TOKEN':CSRF,Accept:'application/json'}});
        const d=await r.json();
        box.innerHTML='<div class="alert '+(d.ok?'alert-ok':'alert-bad')+'">'+d.message+'</div>';
    }catch(e){ box.innerHTML='<div class="alert alert-bad">Error al probar la conexión</div>'; }
    btn.disabled=false; btn.textContent=old;
});

// ── Menú "⋯ Más acciones": cerrar al hacer clic afuera o en una opción ──
(function(){
    const menu = document.getElementById('actMenu');
    if(!menu) return;
    // al elegir una opción (incluida "Probar conexión") se cierra el menú
    menu.querySelectorAll('a, button').forEach(el =>
        el.addEventListener('click', () => menu.removeAttribute('open')));
    document.addEventListener('click', e => {
        if(menu.open && !menu.contains(e.target)) menu.removeAttribute('open');
    });
    document.addEventListener('keydown', e => { if(e.key === 'Escape') menu.removeAttribute('open'); });
})();
</script>
@endpush
