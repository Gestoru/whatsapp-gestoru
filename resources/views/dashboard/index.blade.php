@extends('dashboard.layout')
@section('title', config('dashboard.title'))

@section('actions')
    <a href="{{ route('dashboard.servers.create') }}" class="btn btn-primary btn-sm">＋ Agregar servidor</a>
@endsection

@section('content')
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
                <a href="{{ route('dashboard.servers.show', $server) }}" class="card link" data-server="{{ $server->id }}"
                   data-metrics-url="{{ route('dashboard.servers.metrics', $server) }}">
                    <div class="accent-bar" style="background:linear-gradient(90deg,{{ $server->color }},transparent)"></div>
                    <div class="row" style="justify-content:space-between;align-items:flex-start">
                        <div>
                            <div style="font-size:17px;font-weight:700">{{ $server->name }}</div>
                            <div class="muted tiny" style="margin-top:2px">{{ $server->provider_label }} · {{ $server->host }}</div>
                        </div>
                        <span class="pill js-status"><span class="dot"></span><span class="js-status-text">Consultando…</span></span>
                    </div>

                    <div class="js-metrics" style="margin-top:16px">
                        @foreach(['cpu'=>'CPU','mem'=>'RAM','disk'=>'Disco'] as $k=>$label)
                            <div style="margin-bottom:10px">
                                <div class="metric-label"><span class="muted">{{ $label }}</span><span class="js-{{ $k }}-txt muted">—</span></div>
                                <div class="meter"><span class="js-{{ $k }}-bar" style="width:0%;background:var(--muted)"></span></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="muted tiny js-os" style="margin-top:6px"></div>
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
