@extends('dashboard.layout')
@section('title', 'Tendencias · '.$server->name)
@section('subtitle', 'monitoreo en tiempo real de '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
    <a href="{{ route('dashboard.servers.analytics', $server) }}" class="btn btn-sm">📈 Análisis</a>
@endsection

@php
    $ranges = [6 => '6 h', 24 => '24 h', 72 => '3 días', 168 => '7 días'];
    $W = 1000; $H = 200; $padL = 4; $padR = 4; $padT = 12; $padB = 16;
    $n = $samples->count();
    $build = function ($accessor) use ($samples, $n, $W, $H, $padL, $padR, $padT, $padB) {
        if ($n === 0) return ['line' => '', 'area' => '', 'dots' => []];
        $iW = $W - $padL - $padR; $iH = $H - $padT - $padB;
        $pts = []; $dots = []; $i = 0;
        foreach ($samples as $s) {
            $v = $accessor($s); $v = $v === null ? 0 : max(0, min(100, $v));
            $x = $padL + ($n <= 1 ? $iW/2 : $iW * $i/($n-1));
            $y = $padT + $iH * (1 - $v/100);
            $pts[] = round($x,1).','.round($y,1);
            if ($v >= 75) $dots[] = [round($x,1), round($y,1), $v];
            $i++;
        }
        $line = implode(' ', $pts);
        $f = explode(',', $pts[0]); $l = explode(',', $pts[count($pts)-1]); $b = $padT + $iH;
        return ['line' => $line, 'area' => $f[0].','.$b.' '.$line.' '.$l[0].','.$b, 'dots' => $dots];
    };
    $charts = [
        ['cpu','CPU','#ff4d6d','🔥', fn($s)=>$s->cpu_pct],
        ['mem','RAM','#22e39b','🧠', fn($s)=>$s->memPct()],
        ['disk','Disco','#38bdf8','💾', fn($s)=>$s->diskPct()],
    ];
@endphp

@push('scripts')
<style>
    .live-wrap{background:radial-gradient(120% 140% at 50% -20%,#1a2450 0%,#0d1430 60%);border:1px solid #2b3a66;
        border-radius:18px;padding:20px;position:relative;overflow:hidden;margin-bottom:18px}
    .live-wrap::before{content:'';position:absolute;inset:0;background:
        repeating-linear-gradient(90deg,transparent 0 39px,#ffffff08 39px 40px),
        repeating-linear-gradient(0deg,transparent 0 39px,#ffffff08 39px 40px);pointer-events:none}
    .live-badge{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:700;letter-spacing:.5px;
        color:#7dd3fc;text-transform:uppercase}
    .live-badge .pulse{width:9px;height:9px;border-radius:50%;background:#22e39b;box-shadow:0 0 0 0 #22e39b99;animation:pulse 1.8s infinite}
    @keyframes pulse{0%{box-shadow:0 0 0 0 #22e39baa}70%{box-shadow:0 0 0 10px #22e39b00}100%{box-shadow:0 0 0 0 #22e39b00}}
    .gauges{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:16px;position:relative;z-index:1}
    .gauge{text-align:center}
    .gauge svg{width:130px;height:130px;filter:drop-shadow(0 0 8px currentColor)}
    .gauge .val{font-size:26px;font-weight:800;fill:#fff}
    .gauge .cap{font-size:13px;color:#9fb0d8;margin-top:2px;font-weight:600}
    .gauge .sub{font-size:11px;color:#64748b;font-family:ui-monospace,monospace}
    .live-chips{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;position:relative;z-index:1}
    .chip2{background:#0e1836cc;border:1px solid #2b3a66;border-radius:10px;padding:8px 13px}
    .chip2 .k{font-size:11px;color:#7286b5} .chip2 .v{font-size:15px;font-weight:700}
    .glow-line{filter:drop-shadow(0 0 4px currentColor)}
    .stat-fx{background:linear-gradient(180deg,#151d36,#0f1730);border:1px solid #26324f;border-radius:12px;padding:14px}
    .stat-fx .n{font-size:26px;font-weight:800;line-height:1}
</style>
@endpush

@section('content')
    <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:4px">
        <h1 style="margin:0">📡 {{ $server->name }}</h1>
        <div class="row">
            @foreach($ranges as $h => $label)
                <a href="{{ route('dashboard.servers.trends', $server) }}?h={{ $h }}"
                   class="btn btn-sm {{ $hours === $h ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    {{-- ═══ EN VIVO ═══ --}}
    <div class="live-wrap" data-metrics-url="{{ route('dashboard.servers.metrics', $server) }}">
        <div class="row" style="justify-content:space-between">
            <span class="live-badge"><span class="pulse"></span> En vivo · se actualiza solo</span>
            <span class="tiny muted" id="live-time">—</span>
        </div>
        <div class="gauges">
            @foreach([['cpu','CPU','#ff4d6d'],['mem','RAM','#22e39b'],['disk','Disco','#38bdf8']] as [$k,$label,$color])
                <div class="gauge" style="color:{{ $color }}">
                    <svg viewBox="0 0 120 120">
                        <circle cx="60" cy="60" r="52" fill="none" stroke="#22314f" stroke-width="10"/>
                        <circle id="g-{{ $k }}" cx="60" cy="60" r="52" fill="none" stroke="{{ $color }}" stroke-width="10"
                                stroke-linecap="round" stroke-dasharray="327" stroke-dashoffset="327"
                                transform="rotate(-90 60 60)" style="transition:stroke-dashoffset .8s ease"/>
                        <text id="t-{{ $k }}" x="60" y="66" text-anchor="middle" class="val">—</text>
                    </svg>
                    <div class="cap">{{ $label }}</div>
                    <div class="sub" id="s-{{ $k }}">&nbsp;</div>
                </div>
            @endforeach
        </div>
        <div class="live-chips">
            <div class="chip2"><div class="k">Sistema</div><div class="v" id="c-os" style="font-size:13px">—</div></div>
            <div class="chip2"><div class="k">Uptime</div><div class="v" id="c-uptime">—</div></div>
            <div class="chip2"><div class="k">Carga 1/5/15m</div><div class="v" id="c-load" style="font-size:14px">—</div></div>
            <div class="chip2"><div class="k">Núcleos</div><div class="v" id="c-cores">—</div></div>
            <div class="chip2"><div class="k">Proceso #1 CPU</div><div class="v" id="c-topcpu" style="font-size:12px;font-family:ui-monospace,monospace">—</div></div>
        </div>
    </div>

    {{-- ═══ Estadísticas del rango ═══ --}}
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:6px">
        <div class="stat-fx"><div class="k muted tiny">CPU promedio</div><div class="n" style="color:#7dd3fc">{{ $stats['avg'] ?? '—' }}%</div></div>
        <div class="stat-fx"><div class="k muted tiny">CPU máximo</div><div class="n" style="color:#ff4d6d">{{ $stats['max'] ?? '—' }}%</div></div>
        <div class="stat-fx"><div class="k muted tiny">CPU mínimo</div><div class="n" style="color:#22e39b">{{ $stats['min'] ?? '—' }}%</div></div>
        <div class="stat-fx"><div class="k muted tiny">Eventos de pico</div><div class="n" style="color:#fcd34d">{{ count($events) }}</div></div>
        <div class="stat-fx"><div class="k muted tiny">Muestras</div><div class="n">{{ $stats['count'] }}</div></div>
    </div>

    @if($samples->isEmpty())
        <div class="card empty" style="margin-top:14px">
            <div class="big">⏳</div>
            <h1>El histórico se está llenando</h1>
            <p class="muted" style="margin:10px 0 4px">Los medidores de arriba ya funcionan en vivo. Las gráficas de tendencia necesitan unos minutos de muestreo — vuelve en 10–15 min.</p>
        </div>
    @else
        {{-- ═══ Gráficas con brillo ═══ --}}
        @foreach($charts as [$key,$label,$color,$ic,$accessor])
            @php($p = $build($accessor))
            <h2><span class="section-ic" style="background:{{ $color }}22;border-color:{{ $color }}66;color:{{ $color }}">{{ $ic }}</span>
                {{ $label }} <span class="muted tiny" style="font-weight:400">· en el rango</span></h2>
            <div class="card" style="padding:12px;background:linear-gradient(180deg,#111a34,#0b1226)">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" style="width:100%;height:170px;display:block">
                    <defs>
                        <linearGradient id="grad-{{ $key }}" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="{{ $color }}" stop-opacity="0.35"/>
                            <stop offset="100%" stop-color="{{ $color }}" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    @foreach([0,25,50,75,100] as $g)
                        @php($gy = $padT + ($H-$padT-$padB)*(1-$g/100))
                        <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $W-$padR }}" y2="{{ $gy }}" stroke="#22314f" stroke-width="1" stroke-dasharray="{{ $g===0||$g===100?'0':'2 5' }}"/>
                        <text x="{{ $W-$padR-2 }}" y="{{ $gy-2 }}" fill="#4b5b82" font-size="9" text-anchor="end">{{ $g }}</text>
                    @endforeach
                    <polygon points="{{ $p['area'] }}" fill="url(#grad-{{ $key }})"/>
                    <polyline points="{{ $p['line'] }}" fill="none" stroke="{{ $color }}" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" class="glow-line" style="color:{{ $color }}"/>
                    @foreach($p['dots'] as [$dx,$dy,$dv])
                        <circle cx="{{ $dx }}" cy="{{ $dy }}" r="3.5" fill="#fff" stroke="{{ $color }}" stroke-width="2"/>
                    @endforeach
                </svg>
                <div class="row" style="justify-content:space-between;margin-top:4px">
                    <span class="muted tiny">{{ $samples->first()->sampled_at->format('d/m H:i') }}</span>
                    <span class="muted tiny">{{ $samples->last()->sampled_at->format('d/m H:i') }}</span>
                </div>
            </div>
        @endforeach

        {{-- ═══ REPORTE DE EVENTOS DE PICO ═══ --}}
        <h2><span class="section-ic">🚨</span> Reporte de eventos de pico <span class="muted tiny" style="font-weight:400">· CPU ≥ {{ $threshold }}%</span></h2>
        @if(empty($events))
            <div class="list-card" style="padding:18px">
                <span class="muted tiny">✅ Sin picos de CPU en este rango. Todo estable.</span>
            </div>
        @else
            <div class="grid" style="gap:12px">
                @foreach($events as $ev)
                    @php($dur = $ev['start']->diffInMinutes($ev['end']))
                    <div class="card" style="padding:14px 16px;border-left:3px solid #ff4d6d">
                        <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
                            <div>
                                <div style="font-weight:700;font-size:15px">
                                    🔴 Pico de {{ $ev['peak']->cpu_pct }}% CPU
                                    <span class="muted tiny" style="font-weight:400">· {{ $ev['start']->format('d/m H:i') }}@if($dur>0)–{{ $ev['end']->format('H:i') }} ({{ $dur }} min)@endif</span>
                                </div>
                                <div class="tiny muted" style="margin-top:4px">
                                    RAM {{ $ev['peak']->memPct() }}% · carga {{ $ev['peak']->load1 }} · {{ $ev['n'] }} muestra(s)
                                </div>
                            </div>
                            <div style="text-align:right;max-width:55%">
                                <div class="tiny muted">Proceso que más consumía</div>
                                <div style="font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;color:#fca5a5">
                                    {{ $ev['peak']->top_cpu_cmd ?? '—' }} @if($ev['peak']->top_cpu_pct)({{ $ev['peak']->top_cpu_pct }}%)@endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endsection

@push('scripts')
<script>
const CIRC = 327; // 2·π·52
const url = document.querySelector('.live-wrap').dataset.metricsUrl;
function setGauge(k, pct){
    const c = document.getElementById('g-'+k), t = document.getElementById('t-'+k);
    if(pct==null){ t.textContent='—'; return; }
    c.style.strokeDashoffset = CIRC * (1 - pct/100);
    t.textContent = pct + '%';
    const col = pct>=90?'#ff4d6d':pct>=70?'#fbbf24':(k==='cpu'?'#ff4d6d':k==='mem'?'#22e39b':'#38bdf8');
    c.setAttribute('stroke', col);
    c.closest('.gauge').style.color = col;
}
async function tick(){
    try{
        const r = await fetch(url,{headers:{Accept:'application/json'}});
        const d = await r.json();
        if(!d.ok){ document.getElementById('live-time').textContent='sin conexión'; return; }
        const m = d.metrics;
        setGauge('cpu', m.cpu_pct);
        setGauge('mem', m.mem.pct);
        setGauge('disk', m.disk.pct);
        document.getElementById('s-cpu').innerHTML = (m.cpu_cores||'?')+' núcleos';
        document.getElementById('s-mem').textContent = m.mem.used+' / '+m.mem.total;
        document.getElementById('s-disk').textContent = m.disk.used+' / '+m.disk.total;
        document.getElementById('c-os').textContent = m.os;
        document.getElementById('c-uptime').textContent = m.uptime;
        document.getElementById('c-load').textContent = m.load;
        document.getElementById('c-cores').textContent = m.cpu_cores;
        const now = new Date();
        document.getElementById('live-time').textContent = 'actualizado ' + now.toLocaleTimeString();
    }catch(e){ document.getElementById('live-time').textContent='sin conexión'; }
}
tick();
setInterval(tick, 8000);
</script>
@endpush
