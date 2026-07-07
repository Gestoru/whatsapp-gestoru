@extends('dashboard.layout')
@section('title', config('dashboard.title'))

@section('actions')
    @if($contaboConfigured)
        <form method="POST" action="{{ route('dashboard.contabo.sync') }}" style="display:inline">
            @csrf
            <button class="btn btn-sm" title="Trae estado y plan de tus VPS Contabo">🔄 Sincronizar Contabo</button>
        </form>
    @endif
    <button class="btn btn-sm" onclick="document.getElementById('contabo-box').classList.toggle('hidden')">🟠 Contabo</button>
    <form method="POST" action="{{ route('dashboard.tools.slowlog') }}" style="display:inline"
          onsubmit="return confirm('Activar el registro de consultas lentas de MySQL en TODOS los servidores conectados. Es seguro (solo activa un registro). ¿Continuar?')">
        @csrf
        <button class="btn btn-sm" title="Activa el slow query log de MySQL en todos los servidores">🐢 Activar slow log (todos)</button>
    </form>
    <a href="{{ route('dashboard.servers.create') }}" class="btn btn-primary btn-sm">＋ Agregar servidor</a>
@endsection

@section('content')
    {{-- Conexión con Contabo --}}
    <div id="contabo-box" class="card hidden" style="margin-bottom:16px">
        <h2 style="font-size:15px;margin:0 0 6px">🟠 Conectar con Contabo</h2>
        <p class="muted tiny" style="margin:0 0 12px">
            Trae automáticamente el estado, plan, región y la próxima renovación de todos tus VPS de Contabo.
            @if($contaboConfigured)<span style="color:var(--ok)">✔ Conectado.</span>@endif
        </p>
        <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
            <strong style="color:var(--text)">Cómo obtener tus credenciales:</strong>
            <ol style="margin:8px 0 0 18px;padding:0">
                <li>Entra a <span style="font-family:ui-monospace,monospace">my.contabo.com</span> → tu cuenta → <strong style="color:var(--text)">API / Secrets</strong>.</li>
                <li>Copia el <strong style="color:var(--text)">Client Id</strong> y <strong style="color:var(--text)">Client Secret</strong>.</li>
                <li>Tu <strong style="color:var(--text)">usuario API</strong> es tu email de Contabo, y ahí mismo defines/cambias la <strong style="color:var(--text)">API Password</strong>.</li>
            </ol>
        </div>
        <form method="POST" action="{{ route('dashboard.contabo.connect') }}">
            @csrf
            <div class="form-grid">
                <div class="field"><label>Client Id</label><input name="contabo_client_id" value="{{ $contaboClientId }}" required></div>
                <div class="field"><label>Client Secret @if($contaboConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label><input name="contabo_client_secret" type="password" autocomplete="new-password" placeholder="••••••••"></div>
            </div>
            <div class="form-grid">
                <div class="field"><label>Usuario API (tu email de Contabo)</label><input name="contabo_api_user" value="{{ $contaboApiUser }}" required></div>
                <div class="field"><label>API Password @if($contaboConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label><input name="contabo_api_password" type="password" autocomplete="new-password" placeholder="••••••••"></div>
            </div>
            <div class="row" style="justify-content:flex-end;gap:8px">
                <button class="btn btn-primary btn-sm">Guardar credenciales</button>
                @if($contaboConfigured)
                    <button formaction="{{ route('dashboard.contabo.sync') }}" class="btn btn-sm">🔄 Sincronizar ahora</button>
                @endif
            </div>
        </form>
        <p class="muted tiny" style="margin-top:8px">🔒 Los secretos se guardan cifrados. Los VPS se sincronizan solos cada pocas horas.</p>
    </div>

    @if($servers->isEmpty())
        <div class="card empty">
            <div class="big">🗄️</div>
            <h1>Aún no hay servidores</h1>
            <p class="muted" style="margin:10px 0 20px">Agrega tu primer servidor (Contabo, Winhosting u otro) para empezar a monitorearlo.</p>
            <a href="{{ route('dashboard.servers.create') }}" class="btn btn-primary">＋ Agregar servidor</a>
        </div>
    @else
        <div class="grid cards">
            @foreach($servers as $server)
                @php($ls = $latest[$server->id] ?? null)
                @php($init = ['cpu'=>$ls?->cpu_pct, 'mem'=>$ls?->memPct(), 'disk'=>$ls?->diskPct()])
                <a href="{{ route('dashboard.servers.show', $server) }}" class="card link" data-server="{{ $server->id }}"
                   data-metrics-url="{{ route('dashboard.servers.metrics', $server) }}">
                    <div class="accent-bar" style="background:linear-gradient(90deg,{{ $server->color }},transparent)"></div>
                    <div class="row" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <div style="font-size:17px;font-weight:700">{{ $server->name }}</div>
                            <div class="muted tiny" style="margin-top:2px">{{ $server->provider_label }} · {{ $server->host }}</div>
                        </div>
                        <span class="pill js-status"><span class="dot {{ $ls ? 'ok' : '' }}"></span><span class="js-status-text">{{ $ls ? 'En línea' : 'Consultando…' }}</span></span>
                    </div>

                    <div class="js-metrics" style="margin-top:16px">
                        @foreach(['cpu'=>'CPU','mem'=>'RAM','disk'=>'Disco'] as $k=>$label)
                            @php($v = $init[$k])
                            <div style="margin-bottom:10px">
                                <div class="metric-label"><span class="muted">{{ $label }}</span><span class="js-{{ $k }}-txt {{ $v===null?'muted':'' }}">{{ $v!==null ? $v.'%' : '—' }}</span></div>
                                <div class="meter"><span class="js-{{ $k }}-bar" style="width:{{ $v ?? 0 }}%;background:{{ $v===null?'var(--muted)':($v>=90?'var(--bad)':($v>=70?'var(--warn)':'var(--ok)')) }}"></span></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="muted tiny js-os" style="margin-top:6px">{{ $ls ? 'última lectura '.$ls->sampled_at->diffForHumans() : '' }}</div>
                </a>
            @endforeach
        </div>
    @endif
@endsection

@push('scripts')
<script>
const barColor = p => p >= 90 ? 'var(--bad)' : p >= 70 ? 'var(--warn)' : 'var(--ok)';

function paintMetric(card, key, m){
    const bar = card.querySelector('.js-'+key+'-bar');
    const txt = card.querySelector('.js-'+key+'-txt');
    if(!m || m.pct === null || m.pct === undefined){ txt.textContent='—'; return; }
    bar.style.width = m.pct + '%';
    bar.style.background = barColor(m.pct);
    txt.classList.remove('muted');
    txt.textContent = key==='cpu' ? m.pct+'%' : (m.used+' / '+m.total+' ('+m.pct+'%)');
}

async function loadCard(card){
    const statusText = card.querySelector('.js-status-text');
    const dot = card.querySelector('.js-status .dot');
    try{
        const r = await fetch(card.dataset.metricsUrl, {headers:{'Accept':'application/json'}});
        const d = await r.json();
        if(d.needs_credentials){ markNeedsPassword(card); return; }
        if(!d.ok) throw new Error(d.error||'error');
        const m = d.metrics;
        dot.classList.add('ok'); statusText.textContent = 'En línea';
        paintMetric(card,'cpu',{pct:m.cpu_pct,used:'',total:''});
        card.querySelector('.js-cpu-txt').textContent = (m.cpu_pct??'—')+(m.cpu_pct!=null?'%':'');
        paintMetric(card,'mem',m.mem);
        paintMetric(card,'disk',m.disk);
        card.querySelector('.js-os').textContent = (m.os||'') + (m.uptime? ' · uptime '+m.uptime : '');
    }catch(e){
        dot.classList.add('bad'); statusText.textContent = 'Sin conexión';
    }
}

function markNeedsPassword(card){
    const dot = card.querySelector('.js-status .dot');
    dot.style.background = 'var(--warn)'; dot.style.boxShadow = '0 0 8px var(--warn)';
    card.querySelector('.js-status-text').textContent = 'Falta contraseña';
    card.querySelector('.js-os').textContent = 'Ábrelo y ponle su contraseña root para empezar a monitorearlo';
}
document.querySelectorAll('[data-server]').forEach(loadCard);
</script>
@endpush
