@extends('dashboard.layout')
@section('title', 'Panel · '.$server->name)
@section('subtitle', 'monitoreo en tiempo real de '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
    <a href="#analisis" class="btn btn-sm">🔬 Ir al análisis</a>
    <a href="{{ route('dashboard.servers.queries', $server) }}" class="btn btn-sm">🧠 Optimizador SQL</a>
@endsection

@php
    $ranges = [6 => '6 h', 24 => '24 h', 72 => '3 días', 168 => '7 días'];
    $W = 1000; $H = 200; $padL = 4; $padR = 4; $padT = 12; $padB = 16;
    $n = $samples->count();
    $peakAt = $threshold ?? 50;
    $build = function ($accessor, $unit = '%') use ($samples, $n, $W, $H, $padL, $padR, $padT, $padB, $peakAt) {
        if ($n === 0) return ['line' => '', 'area' => '', 'dots' => [], 'points' => []];
        $iW = $W - $padL - $padR; $iH = $H - $padT - $padB;
        $pts = []; $dots = []; $points = []; $i = 0;
        foreach ($samples as $s) {
            $raw = $accessor($s); $v = $raw === null ? 0 : max(0, min(100, $raw));
            $x = $padL + ($n <= 1 ? $iW/2 : $iW * $i/($n-1));
            $y = $padT + $iH * (1 - $v/100);
            $pts[] = round($x,1).','.round($y,1);
            $points[] = [round($x,1), round($y,1), $s->sampled_at->format('d/m H:i').' · '.($raw === null ? 's/d' : $raw.$unit)];
            if ($v >= $peakAt) $dots[] = [round($x,1), round($y,1), $v];
            $i++;
        }
        $line = implode(' ', $pts);
        $f = explode(',', $pts[0]); $l = explode(',', $pts[count($pts)-1]); $b = $padT + $iH;
        return ['line' => $line, 'area' => $f[0].','.$b.' '.$line.' '.$l[0].','.$b, 'dots' => $dots, 'points' => $points];
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
    /* ── Modo sísmico: pico crítico de CPU ── */
    @keyframes quake{0%,100%{transform:translate(0,0)}10%{transform:translate(-3px,1px)}20%{transform:translate(3px,-1px)}
        30%{transform:translate(-2px,-2px)}40%{transform:translate(2px,2px)}50%{transform:translate(-3px,0)}
        60%{transform:translate(3px,1px)}70%{transform:translate(-1px,2px)}80%{transform:translate(2px,-2px)}90%{transform:translate(-2px,1px)}}
    .quake{animation:quake .45s linear infinite;border-color:#ff4d6d!important;box-shadow:0 0 34px #ff4d6d66}
    #crit-banner{display:none;align-items:center;gap:10px;background:#3a0f1a;border:1px solid #ff4d6d;color:#fecaca;
        border-radius:12px;padding:10px 14px;margin:12px 0 0;font-weight:700;font-size:14px;position:relative;z-index:2;
        animation:critblink 1.1s ease-in-out infinite;flex-wrap:wrap}
    @keyframes critblink{50%{background:#57121f;box-shadow:0 0 18px #ff4d6d55}}
    /* Tooltip interactivo de las gráficas: el dato sale con solo pasar el mouse */
    .trend-chart{cursor:crosshair;touch-action:none}
    .trend-chart circle[fill="transparent"]{pointer-events:none}
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
            <span class="row" style="gap:10px">
                <button id="alarm-toggle" type="button" class="btn btn-ghost btn-sm" style="position:relative;z-index:2" title="Suena una alarma sísmica cuando la CPU pasa del umbral crítico">🔊</button>
                <span class="tiny muted" id="live-time">—</span>
            </span>
        </div>
        <div id="crit-banner"><span id="crit-text"></span></div>
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

    @php($expected = $hours * 12)
    @if($stats['count'] > 0 && $stats['count'] < (int) ($expected * 0.6))
        <div class="alert" style="border-color:#4a3a14;background:#2a2210;color:#fcd34d;font-size:13px;margin:10px 0 0">
            ⚠️ Llegaron {{ $stats['count'] }} de ~{{ $expected }} muestras esperadas en este rango (1 cada 5 min).
            Si acaba de actualizar el panel, el muestreo se normaliza solo; si persiste, revisa <code>storage/logs/laravel.log</code> en el servidor del panel.
        </div>
    @endif

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
                <svg class="trend-chart" viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" style="width:100%;height:170px;display:block">
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
                    {{-- Puntos invisibles con tooltip al pasar el mouse --}}
                    @foreach($p['points'] as [$dx,$dy,$tip])
                        <circle cx="{{ $dx }}" cy="{{ $dy }}" r="8" fill="transparent" style="cursor:pointer">
                            <title>{{ $tip }}</title>
                        </circle>
                    @endforeach
                </svg>
                <div class="row" style="justify-content:space-between;margin-top:4px">
                    <span class="muted tiny">{{ $samples->first()->sampled_at->format('d/m H:i') }}</span>
                    <span class="muted tiny">{{ $samples->last()->sampled_at->format('d/m H:i') }}</span>
                </div>
            </div>
        @endforeach

        {{-- ═══ ACTIVIDAD DE MYSQL ═══ --}}
        @if($hasMysql)
            <h2><span class="section-ic" style="background:#a78bfa22;border-color:#a78bfa66;color:#a78bfa">🗄️</span>
                Actividad de MySQL <span class="muted tiny" style="font-weight:400">· {{ $mysqlNow->mysql_conns }} conexiones · {{ $mysqlNow->mysql_running ?? '—' }} consultas activas <span style="opacity:.7">({{ $mysqlNow->sampled_at->format('H:i') }})</span></span>
            </h2>
            @foreach($mysqlCharts as $mc)
                <div class="card" style="padding:12px;margin-bottom:12px;background:linear-gradient(180deg,#111a34,#0b1226)">
                    <div class="row" style="justify-content:space-between;margin-bottom:4px">
                        <span style="font-weight:600;font-size:14px;color:{{ $mc['color'] }}">{{ $mc['label'] }}</span>
                        <span class="muted tiny">máx en rango: {{ $mc['max'] }}</span>
                    </div>
                    <svg class="trend-chart" viewBox="0 0 {{ $W }} 120" preserveAspectRatio="none" style="width:100%;height:110px;display:block">
                        <defs><linearGradient id="mg-{{ $mc['key'] }}" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="{{ $mc['color'] }}" stop-opacity="0.35"/><stop offset="100%" stop-color="{{ $mc['color'] }}" stop-opacity="0"/>
                        </linearGradient></defs>
                        <polygon points="{{ $mc['area'] }}" fill="url(#mg-{{ $mc['key'] }})"/>
                        <polyline points="{{ $mc['line'] }}" fill="none" stroke="{{ $mc['color'] }}" stroke-width="2.5" stroke-linejoin="round" class="glow-line" style="color:{{ $mc['color'] }}"/>
                        @foreach($mc['points'] as [$dx,$dy,$tip])
                            <circle cx="{{ $dx }}" cy="{{ $dy }}" r="8" fill="transparent" style="cursor:pointer"><title>{{ $tip }}</title></circle>
                        @endforeach
                    </svg>
                </div>
            @endforeach
        @endif

        {{-- ═══ REPORTE DE EVENTOS DE PICO ═══ --}}
        <h2><span class="section-ic">🚨</span> Reporte de eventos de pico <span class="muted tiny" style="font-weight:400">· CPU ≥ {{ $threshold }}%</span></h2>
        <div class="grid" id="events-grid" style="gap:12px;margin-bottom:12px"></div>
        @if(empty($events))
            <div class="list-card" id="events-empty" style="padding:18px">
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

    {{-- ═══ ANÁLISIS PROFUNDO (antes página aparte) ═══ --}}
    <h2 id="analisis" style="margin-top:34px"><span class="section-ic" style="background:#6366f122;border-color:#6366f166;color:#a5b4fc">🔬</span>
        Análisis profundo <span class="muted tiny" style="font-weight:400">· foto del servidor en este momento</span>
        <button id="an-refresh" class="btn btn-ghost btn-sm" style="margin-left:auto">🔄 Actualizar</button>
    </h2>
    <div id="an-body" data-url="{{ route('dashboard.servers.analytics.panel', $server) }}">
        <div class="list-card" style="padding:22px;text-align:center">
            <span class="spin"></span>
            <div class="muted tiny" style="margin-top:10px">Analizando el servidor por SSH (procesos, tráfico por dominio, MySQL)… unos segundos.</div>
        </div>
    </div>
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
        handleCritical(m, d);
    }catch(e){ document.getElementById('live-time').textContent='sin conexión'; }
}
tick();
setInterval(tick, 8000);

// ═══ MODO SÍSMICO: pico crítico de CPU ═══
const CRIT       = {{ (int) config('dashboard.cpu_critical_threshold', 90) }};
const BASE_TITLE = document.title;
const liveWrap   = document.querySelector('.live-wrap');
const banner     = document.getElementById('crit-banner');
const alarmBtn   = document.getElementById('alarm-toggle');
let audioCtx = null, lastQuakeSound = 0, critActive = false;
let alarmOn = localStorage.getItem('cpuAlarm') !== 'off';

function paintAlarmBtn(){
    alarmBtn.textContent = alarmOn ? '🔊 Alarma: activa' : '🔇 Alarma: apagada';
    alarmBtn.style.color = alarmOn ? '#22e39b' : '#94a3c4';
}
paintAlarmBtn();

function ensureAudio(){
    try{
        if(!audioCtx) audioCtx = new (window.AudioContext||window.webkitAudioContext)();
        if(audioCtx.state === 'suspended') audioCtx.resume();
    }catch(_){}
}
// Los navegadores solo permiten sonido tras una interacción: se arma con el primer clic/tecla
document.addEventListener('pointerdown', ensureAudio);
document.addEventListener('keydown', ensureAudio);

alarmBtn.addEventListener('click', () => {
    alarmOn = !alarmOn;
    localStorage.setItem('cpuAlarm', alarmOn ? 'on' : 'off');
    paintAlarmBtn();
    ensureAudio();
    if(alarmOn) quakeSound(0.35); // pequeña muestra al activarla
});

// Sonido sísmico "prudente": retumbo grave + dos tonos de aviso (~3 s, volumen moderado)
function quakeSound(vol = 0.5){
    if(!audioCtx || audioCtx.state !== 'running') return;
    const t0 = audioCtx.currentTime, dur = 2.8;
    // Retumbo de terremoto: ruido filtrado a frecuencias bajas
    const buf = audioCtx.createBuffer(1, audioCtx.sampleRate * dur, audioCtx.sampleRate);
    const data = buf.getChannelData(0);
    for(let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    const noise = audioCtx.createBufferSource(); noise.buffer = buf;
    const lp = audioCtx.createBiquadFilter(); lp.type = 'lowpass';
    lp.frequency.setValueAtTime(95, t0); lp.frequency.linearRampToValueAtTime(45, t0 + dur);
    const ng = audioCtx.createGain();
    ng.gain.setValueAtTime(0.0001, t0);
    ng.gain.exponentialRampToValueAtTime(vol, t0 + 0.3);
    ng.gain.exponentialRampToValueAtTime(0.0001, t0 + dur);
    noise.connect(lp); lp.connect(ng); ng.connect(audioCtx.destination);
    noise.start(t0);
    // Dos avisos tonales discretos encima del retumbo
    [0.15, 1.3].forEach(off => {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine';
        o.frequency.setValueAtTime(620, t0 + off);
        o.frequency.exponentialRampToValueAtTime(880, t0 + off + 0.4);
        g.gain.setValueAtTime(0.0001, t0 + off);
        g.gain.exponentialRampToValueAtTime(vol * 0.45, t0 + off + 0.06);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + off + 0.75);
        o.connect(g); g.connect(audioCtx.destination);
        o.start(t0 + off); o.stop(t0 + off + 0.8);
    });
}

function handleCritical(m, d){
    const cpu  = m.cpu_pct;
    const crit = cpu != null && cpu >= CRIT;

    if(crit){
        banner.style.display = 'flex';
        let txt = '🚨 PICO CRÍTICO: CPU al ' + cpu + '%';
        if(d.peak && d.peak.process) txt += ' · proceso: ' + d.peak.process.slice(0, 70) + (d.peak.pct ? ' (' + d.peak.pct + '%)' : '');
        if(d.peak && d.peak.captured) txt += ' · ✔ registrado en el reporte de picos';
        document.getElementById('crit-text').textContent = txt;
        if(d.peak && d.peak.process) document.getElementById('c-topcpu').textContent = d.peak.process.slice(0, 55);
        liveWrap.classList.add('quake');
        document.title = '🚨 CPU ' + cpu + '% · ' + BASE_TITLE;
        if(alarmOn && Date.now() - lastQuakeSound > 45000){ ensureAudio(); quakeSound(); lastQuakeSound = Date.now(); }
        if(d.peak && d.peak.captured) prependLiveEvent(cpu, m, d.peak);
    }else{
        banner.style.display = 'none';
        liveWrap.classList.remove('quake');
        if(critActive) document.title = BASE_TITLE;
    }
    critActive = crit;
}

// ═══ TOOLTIP INTERACTIVO EN LAS GRÁFICAS ═══
// Mueve el mouse (o el dedo) por cualquier parte de la gráfica y una guía
// muestra el valor y la hora del punto más cercano.
const chartTip = document.createElement('div');
chartTip.style.cssText = 'position:fixed;display:none;z-index:99;background:#0e1836;border:1px solid #3b4a76;'
    + 'border-radius:8px;padding:6px 10px;font:600 12px ui-monospace,monospace;color:#e7ecf6;'
    + 'pointer-events:none;box-shadow:0 6px 18px #000a;white-space:nowrap';
document.body.appendChild(chartTip);
const SVGNS = 'http://www.w3.org/2000/svg';

document.querySelectorAll('svg.trend-chart').forEach(svg => {
    const pts = [...svg.querySelectorAll('circle[fill="transparent"]')].map(c => ({
        x: +c.getAttribute('cx'),
        y: +c.getAttribute('cy'),
        txt: (c.querySelector('title') || {}).textContent || ''
    }));
    if(!pts.length) return;

    const color = svg.querySelector('polyline')?.getAttribute('stroke') || '#7dd3fc';
    const vb = svg.viewBox.baseVal;

    const guide = document.createElementNS(SVGNS, 'line');
    guide.setAttribute('stroke', color); guide.setAttribute('stroke-width', '1');
    guide.setAttribute('stroke-dasharray', '4 4'); guide.setAttribute('opacity', '.7');
    guide.setAttribute('y1', 0); guide.setAttribute('y2', vb.height);
    guide.style.display = 'none'; svg.appendChild(guide);

    const dot = document.createElementNS(SVGNS, 'circle');
    dot.setAttribute('r', '4.5'); dot.setAttribute('fill', '#fff');
    dot.setAttribute('stroke', color); dot.setAttribute('stroke-width', '2');
    dot.style.display = 'none'; svg.appendChild(dot);

    svg.addEventListener('pointermove', ev => {
        const r = svg.getBoundingClientRect();
        const x = (ev.clientX - r.left) / r.width * vb.width;
        let best = pts[0];
        for(const p of pts) if(Math.abs(p.x - x) < Math.abs(best.x - x)) best = p;

        guide.setAttribute('x1', best.x); guide.setAttribute('x2', best.x); guide.style.display = '';
        dot.setAttribute('cx', best.x); dot.setAttribute('cy', best.y); dot.style.display = '';

        chartTip.textContent = best.txt;
        chartTip.style.display = 'block';
        let lx = ev.clientX + 14;
        if(lx + chartTip.offsetWidth > window.innerWidth - 8) lx = ev.clientX - chartTip.offsetWidth - 14;
        chartTip.style.left = lx + 'px';
        chartTip.style.top = (ev.clientY - 14) + 'px';
    });
    svg.addEventListener('pointerleave', () => {
        guide.style.display = 'none'; dot.style.display = 'none'; chartTip.style.display = 'none';
    });
});

// Inserta el pico recién capturado en el reporte, sin recargar la página
function prependLiveEvent(cpu, m, peak){
    const grid = document.getElementById('events-grid');
    if(!grid) return;
    const empty = document.getElementById('events-empty');
    if(empty) empty.remove();
    const hh = new Date().toLocaleTimeString('es', {hour: '2-digit', minute: '2-digit'});
    const el = document.createElement('div');
    el.className = 'card';
    el.style.cssText = 'padding:14px 16px;border-left:3px solid #ff4d6d;box-shadow:0 0 18px #ff4d6d33';
    el.innerHTML =
        '<div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">'
        + '<div><div style="font-weight:700;font-size:15px">🔴 Pico de ' + cpu + '% CPU '
        + '<span class="muted tiny" style="font-weight:400">· hoy ' + hh + ' · capturado EN VIVO</span></div>'
        + '<div class="tiny muted" style="margin-top:4px">RAM ' + (m.mem.pct ?? '—') + '% · carga ' + (m.load || '—') + '</div></div>'
        + '<div style="text-align:right;max-width:55%"><div class="tiny muted">Proceso que más consumía</div>'
        + '<div style="font-family:ui-monospace,monospace;font-size:12px;word-break:break-all;color:#fca5a5">'
        + (peak.process ? peak.process : '—') + (peak.pct ? ' (' + peak.pct + '%)' : '') + '</div></div></div>';
    grid.prepend(el);
}

// ── Análisis profundo: se carga aparte para no frenar la página ──
const anBody = document.getElementById('an-body');
async function loadAnalysis(){
    anBody.innerHTML = '<div class="list-card" style="padding:22px;text-align:center"><span class="spin"></span>'
        + '<div class="muted tiny" style="margin-top:10px">Analizando el servidor por SSH (procesos, tráfico por dominio, MySQL)… unos segundos.</div></div>';
    try{
        const r = await fetch(anBody.dataset.url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!r.ok) throw new Error('HTTP '+r.status);
        anBody.innerHTML = await r.text();
        initMysqlLive();
    }catch(e){
        anBody.innerHTML = '<div class="alert alert-bad">No se pudo cargar el análisis ('+e.message+'). '
            + '<a href="#analisis" onclick="loadAnalysis();return false" style="text-decoration:underline">Reintentar</a></div>';
    }
}
document.getElementById('an-refresh').addEventListener('click', loadAnalysis);
loadAnalysis();

// ── Consultas de MySQL EN VIVO: la tabla se refresca sola cada 10 s ──
let mysqlLiveTimer = null;
function initMysqlLive(){
    if(mysqlLiveTimer){ clearInterval(mysqlLiveTimer); mysqlLiveTimer = null; }
    const zone = document.querySelector('[data-mysql-live]');
    if(!zone) return;
    const liveUrl = zone.dataset.mysqlLive;
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    const setTxt = (id, v, color) => {
        const el = document.getElementById(id);
        if(el){ el.textContent = v; if(color !== undefined) el.style.color = color; }
    };
    const paint = async () => {
        try{
            const r = await fetch(liveUrl, {headers:{Accept:'application/json'}});
            const d = await r.json();
            if(!d.ok || !d.available) return;
            const meta = document.getElementById('mysql-live-meta');
            if(meta) meta.textContent = '🔴 en vivo · actualizado ' + new Date().toLocaleTimeString();

            const rows    = d.processes || [];
            const longest = rows.length ? Math.max(...rows.map(p => +p.time || 0)) : 0;
            const stuck   = rows.filter(p => +p.time >= 5).length;
            setTxt('ml-conns', d.connections ?? '—');
            setTxt('ml-running', d.running ?? '—');
            setTxt('ml-longest', rows.length ? longest + ' s' : '—', longest >= 5 ? 'var(--bad)' : 'var(--text)');
            setTxt('ml-stuck', stuck, stuck > 0 ? 'var(--bad)' : 'var(--ok)');

            const body = zone.querySelector('.ml-body');
            if(!body) return;
            if(!rows.length){
                body.innerHTML = '<div class="empty" style="padding:20px"><span class="muted tiny">Ninguna consulta pesada en curso 🎉</span></div>';
            }else{
                body.innerHTML = '<table><thead><tr><th>Seg</th><th>BD</th><th>Usuario</th><th>Consulta</th></tr></thead><tbody>'
                    + rows.map(p =>
                        '<tr><td style="font-weight:700;color:' + ((+p.time >= 5) ? 'var(--bad)' : 'var(--text)') + '">' + esc(p.time) + ((+p.time >= 5) ? ' ⚠️' : '') + '</td>'
                        + '<td class="muted tiny">' + esc(p.db) + '</td>'
                        + '<td class="muted tiny">' + esc(p.user) + '</td>'
                        + '<td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">' + esc(p.info) + '</td></tr>'
                    ).join('')
                    + '</tbody></table>';
            }
        }catch(_){ /* siguiente intento en 10 s */ }
    };
    paint();
    mysqlLiveTimer = setInterval(paint, 10000);
}
</script>
@endpush
