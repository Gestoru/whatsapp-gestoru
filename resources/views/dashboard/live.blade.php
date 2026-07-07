@extends('dashboard.layout')
@section('title', 'Usuarios en vivo · '.$server->name)
@section('subtitle', 'quién está conectado ahora · '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← Volver al servidor</a>
    <label class="btn btn-sm" style="gap:6px"><input type="checkbox" id="auto" style="width:auto" checked> Auto</label>
    <button onclick="location.reload()" class="btn btn-primary btn-sm">🔄 Actualizar</button>
@endsection

@section('content')
<style>
    .lv-map{position:relative;background:
        linear-gradient(rgba(38,50,79,.35) 1px,transparent 1px) 0 0/100% 12.5%,
        linear-gradient(90deg,rgba(38,50,79,.35) 1px,transparent 1px) 0 0/8.333% 100%,
        radial-gradient(circle at 50% 40%,#12203f,#0b1327);
        border:1px solid var(--line);border-radius:14px;width:100%;aspect-ratio:2/1;overflow:hidden}
    .lv-cont{position:absolute;font-size:11px;color:#3f4c72;font-weight:700;letter-spacing:.5px;transform:translate(-50%,-50%);pointer-events:none}
    .lv-dot{position:absolute;transform:translate(-50%,-50%);width:12px;height:12px;border-radius:50%;
        background:radial-gradient(circle,#8b5cf6,#6d6cf7);box-shadow:0 0 10px 2px rgba(109,108,247,.7);
        animation:livePulse 2.2s infinite}
    .lv-dot b{position:absolute;top:-7px;left:14px;font-size:10px;color:#cdd6f2;white-space:nowrap;text-shadow:0 1px 3px #000}
    .cbar{height:8px;border-radius:6px;background:#0b1327;overflow:hidden;border:1px solid var(--line)}
    .cbar>span{display:block;height:100%;background:linear-gradient(90deg,var(--accent),var(--cyan))}
</style>

@if($needsCredentials)
    <div class="card empty"><div class="big">🔒</div><h1>Falta la contraseña</h1>
        <p class="muted" style="margin-top:8px">Abre el servidor y ponle su contraseña root para ver quién está conectado.</p></div>
@elseif($error)
    <div class="alert alert-bad">No se pudo leer las conexiones: {{ $error }}</div>
@else
    {{-- Resumen --}}
    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat" style="border-color:#2f3d63">
            <div class="k">👥 Usuarios activos ahora</div>
            <div class="v" style="font-size:30px;color:#c9c8ff">{{ $total }}</div>
        </div>
        <div class="stat"><div class="k">Conexiones abiertas</div><div class="v">{{ $connections }}</div></div>
        <div class="stat"><div class="k">Países</div><div class="v">{{ count($countries) }}</div></div>
        <div class="stat"><div class="k">Internas (proxy/Docker)</div><div class="v muted">{{ $localCount ?? 0 }}</div></div>
    </div>

    @if($total === 0)
        <div class="card empty"><div class="big">🌙</div><h1>Nadie conectado ahora mismo</h1>
            <p class="muted" style="margin-top:8px">No se detectaron clientes externos con actividad web en el último minuto. Esta vista se actualiza sola.</p></div>
        @if($diag)
            @php($noSockets = ($diag['ss_count'] ?? 0) === 0 && ($diag['ct_count'] ?? 0) === 0)
            <div class="card" style="margin-top:14px;padding:14px 16px">
                <div class="row" style="justify-content:space-between;cursor:pointer" onclick="document.getElementById('diag').classList.toggle('hidden')">
                    <span style="font-weight:600">🔧 Diagnóstico de la medición</span>
                    <span class="muted tiny">detalles ▾</span>
                </div>
                <div id="diag" class="hidden" style="margin-top:12px">
                    <div class="stat-grid">
                        <div class="stat"><div class="k">ss (sockets host)</div><div class="v" style="font-size:17px">{{ $diag['ss_count'] }}</div></div>
                        <div class="stat"><div class="k">conntrack (NAT)</div><div class="v" style="font-size:17px">{{ $diag['ct_count'] }}</div></div>
                        <div class="stat"><div class="k">nsenter (dentro de Docker)</div><div class="v" style="font-size:17px">{{ $diag['ns_count'] ?? 0 }}</div></div>
                        <div class="stat"><div class="k">Contenedores</div><div class="v" style="font-size:17px">{{ $diag['containers'] ?? 0 }}</div></div>
                        <div class="stat"><div class="k">conntrack / nsenter</div><div class="v" style="font-size:15px">{{ ($diag['ct_file']||$diag['ct_bin']) ? 'sí' : 'no' }} / {{ ($diag['nsenter'] ?? false) ? 'sí' : 'no' }}</div></div>
                    </div>
                    @php($cont = $diag['containers'] ?? 0)
                    @if($cont > 0 && ! ($diag['nsenter'] ?? false) && ! $diag['ct_file'] && ! $diag['ct_bin'])
                        <div class="alert" style="margin:12px 0 0;background:#2e2410;border-color:#6b5316;color:#fcd34d">
                            ⚠️ Servidor con Docker, pero sin <code>conntrack</code> ni <code>nsenter</code> para ver dentro de los contenedores. En el servidor: <code>sudo apt-get install -y conntrack util-linux</code>.
                        </div>
                    @else
                        <p class="muted tiny" style="margin:12px 0 0">
                            Las fuentes están operativas ({{ $diag['ss_count'] }} por host · {{ $diag['ct_count'] }} por conntrack · {{ $diag['ns_count'] ?? 0 }} dentro de Docker).
                            Si el total es 0, es que no hubo tráfico web externo en la ventana de ~1 min. Refresca en unos segundos.
                        </p>
                    @endif
                </div>
            </div>
        @endif
    @else
    <div class="fb" style="grid-template-columns:1.3fr 1fr;align-items:start;margin-bottom:18px">
        {{-- Mapa aproximado por coordenadas --}}
        <div>
            <h2 style="margin-top:0"><span class="section-ic">🗺️</span> Mapa de conexiones</h2>
            <div class="lv-map" id="map">
                <span class="lv-cont" style="left:22%;top:42%">AMÉRICA</span>
                <span class="lv-cont" style="left:50%;top:38%">EUROPA</span>
                <span class="lv-cont" style="left:54%;top:62%">ÁFRICA</span>
                <span class="lv-cont" style="left:72%;top:44%">ASIA</span>
                <span class="lv-cont" style="left:83%;top:75%">OCEANÍA</span>
                @foreach($visitors as $v)
                    @if($v['lat'] !== null && $v['lon'] !== null)
                        <span class="lv-dot" title="{{ $v['ip'] }} · {{ $v['city'] }} {{ $v['country'] }}"
                              style="left:{{ ($v['lon'] + 180) / 360 * 100 }}%;top:{{ (90 - $v['lat']) / 180 * 100 }}%"></span>
                    @endif
                @endforeach
            </div>
            <p class="muted tiny" style="margin-top:8px">Cada punto es una IP conectada, ubicada por sus coordenadas geográficas.</p>
        </div>

        {{-- Ranking por país --}}
        <div>
            <h2 style="margin-top:0"><span class="section-ic">🌍</span> Por país</h2>
            <div class="card" style="padding:14px">
                @php($maxC = collect($countries)->max('users') ?: 1)
                @forelse($countries as $c)
                    <div style="margin-bottom:12px">
                        <div class="row" style="justify-content:space-between;margin-bottom:5px">
                            <span style="font-weight:600">{{ $c['flag'] }} {{ $c['country'] }}</span>
                            <span class="muted">{{ $c['users'] }}</span>
                        </div>
                        <div class="cbar"><span style="width:{{ (int) round($c['users'] / $maxC * 100) }}%"></span></div>
                    </div>
                @empty
                    <span class="muted tiny">Sin ubicación disponible.</span>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Detalle de IPs --}}
    <h2><span class="section-ic">📡</span> IPs conectadas <span class="muted tiny" style="font-weight:400">({{ count($visitors) }})</span></h2>
    <div class="list-card">
        <table>
            <thead><tr><th>IP</th><th>Ubicación</th><th>Proveedor (ISP)</th><th style="text-align:right">Conexiones</th></tr></thead>
            <tbody>
            @foreach($visitors as $v)
                <tr>
                    <td style="font-family:ui-monospace,monospace">{{ $v['ip'] }}</td>
                    <td>{{ $v['flag'] }} {{ $v['city'] ? $v['city'].', ' : '' }}{{ $v['country'] ?? 'Desconocido' }}</td>
                    <td class="muted">{{ $v['isp'] ?? '—' }}</td>
                    <td style="text-align:right;font-weight:700">{{ $v['conns'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <p class="muted tiny" style="margin-top:14px">
        🔎 <strong>Cómo se mide:</strong> se listan las IPs de cliente con actividad web (puertos 80/443) en aproximadamente
        el último minuto —conexiones activas y recién cerradas—, que equivale a los usuarios navegando ahora. Se excluyen
        las IPs privadas/internas. La ubicación se obtiene por geolocalización de IP.
    </p>
@endif

@push('scripts')
<script>
    (function(){
        const chk = document.getElementById('auto');
        if(!chk) return;
        chk.checked = localStorage.getItem('nexo-live-auto') !== '0';
        let t;
        const arm = () => { clearTimeout(t); if(chk.checked) t = setTimeout(()=>location.reload(), 20000); };
        chk.addEventListener('change', () => { localStorage.setItem('nexo-live-auto', chk.checked ? '1':'0'); arm(); });
        arm();
    })();
</script>
@endpush
@endsection
