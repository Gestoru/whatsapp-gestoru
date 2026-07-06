@extends('dashboard.layout')
@section('title', $server->name.' · '.config('dashboard.title'))
@section('subtitle', $server->provider_label.' · '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.index') }}" class="btn btn-ghost btn-sm">← Servidores</a>
    <button id="btn-test" class="btn btn-sm" data-url="{{ route('dashboard.servers.test', $server) }}">🔌 Probar conexión</button>
    <a href="{{ route('dashboard.servers.edit', $server) }}" class="btn btn-sm">✏️ Editar</a>
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
            </div>
        </div>

        {{-- ── Proyectos ── --}}
        <h2><span class="section-ic">📦</span> Proyectos y servicios <span class="muted tiny" style="font-weight:400">({{ count($projects) }})</span></h2>
        <div class="list-card">
            @if(empty($projects))
                <div class="empty" style="padding:30px"><span class="muted">No se detectaron proyectos, contenedores ni servicios conocidos.</span></div>
            @else
                <table>
                    <thead><tr><th>Tipo</th><th>Nombre</th><th>Detalle</th></tr></thead>
                    <tbody>
                    @foreach($projects as $p)
                        <tr>
                            <td><span class="tag tag-{{ $p['type'] }}">{{ $p['type'] }}</span></td>
                            <td style="font-weight:600">{{ $p['name'] }}</td>
                            <td class="muted tiny" style="font-family:ui-monospace,monospace">{{ $p['detail'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Dominios ── --}}
        <h2><span class="section-ic">🌐</span> Dominios <span class="muted tiny" style="font-weight:400">({{ count($domains) }}) · clic para ver su reporte</span></h2>
        <div class="list-card" style="padding:14px">
            @if(empty($domains))
                <span class="muted tiny">No se detectaron dominios en la configuración de nginx/apache.</span>
            @else
                <div class="row">
                    @foreach($domains as $d)
                        <a href="{{ route('dashboard.servers.domain', $server) }}?d={{ urlencode($d) }}" class="pill" style="cursor:pointer">🌐 {{ $d }} <span class="muted">→</span></a>
                    @endforeach
                </div>
            @endif
        </div>

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
<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
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
</script>
@endpush
