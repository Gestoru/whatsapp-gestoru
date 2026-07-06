@extends('dashboard.layout')
@section('title', 'Tendencias · '.$server->name)
@section('subtitle', 'histórico de '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
    <a href="{{ route('dashboard.servers.analytics', $server) }}" class="btn btn-sm">📈 Análisis</a>
@endsection

@php
    // Rango seleccionable
    $ranges = [6 => '6 horas', 24 => '24 horas', 72 => '3 días', 168 => '7 días'];

    // Construir puntos SVG a partir de las muestras
    $W = 1000; $H = 220; $padL = 4; $padR = 4; $padT = 10; $padB = 18;
    $n = $samples->count();
    $buildPoints = function ($accessor) use ($samples, $n, $W, $H, $padL, $padR, $padT, $padB) {
        if ($n === 0) return ['line' => '', 'area' => ''];
        $innerW = $W - $padL - $padR; $innerH = $H - $padT - $padB;
        $pts = [];
        $i = 0;
        foreach ($samples as $s) {
            $v = $accessor($s);
            $v = $v === null ? 0 : max(0, min(100, $v));
            $x = $padL + ($n <= 1 ? $innerW / 2 : $innerW * $i / ($n - 1));
            $y = $padT + $innerH * (1 - $v / 100);
            $pts[] = round($x, 1).','.round($y, 1);
            $i++;
        }
        $line = implode(' ', $pts);
        $first = explode(',', $pts[0]); $last = explode(',', $pts[count($pts) - 1]);
        $baseY = $padT + $innerH;
        $area = $first[0].','.$baseY.' '.$line.' '.$last[0].','.$baseY;
        return ['line' => $line, 'area' => $area];
    };

    $charts = [
        ['cpu', 'CPU', '#ef4444', fn($s) => $s->cpu_pct],
        ['mem', 'Memoria RAM', '#22c55e', fn($s) => $s->memPct()],
        ['disk', 'Disco', '#38bdf8', fn($s) => $s->diskPct()],
    ];
@endphp

@section('content')
    <div class="row" style="justify-content:space-between;align-items:center">
        <h1 style="margin-bottom:2px">📉 Tendencias de {{ $server->name }}</h1>
        <div class="row">
            @foreach($ranges as $h => $label)
                <a href="{{ route('dashboard.servers.trends', $server) }}?h={{ $h }}"
                   class="btn btn-sm {{ $hours === $h ? 'btn-primary' : 'btn-ghost' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>
    <p class="muted tiny">Se toma una muestra automática cada pocos minutos. {{ $samples->count() }} muestras en el rango.</p>

    @if($samples->isEmpty())
        <div class="card empty" style="margin-top:14px">
            <div class="big">⏳</div>
            <h1>Aún no hay histórico</h1>
            <p class="muted" style="margin:10px 0 4px">El histórico se llena solo con el tiempo. En cuanto el muestreo automático lleve unos minutos corriendo, aquí verás las gráficas de CPU, RAM y disco.</p>
            <p class="muted tiny">Si acabas de actualizar el panel, dale unos 10–15 minutos y recarga esta página.</p>
        </div>
    @else
        {{-- Pico de CPU del rango --}}
        @if($peak && $peak->cpu_pct !== null)
            <div class="card" style="margin:8px 0 6px;padding:14px 16px;background:#2a1116;border-color:#5b2330">
                <div class="row" style="gap:20px">
                    <div>
                        <div class="k muted tiny">Pico de CPU en el rango</div>
                        <div style="font-size:20px;font-weight:800;color:#fca5a5">{{ $peak->cpu_pct }}%</div>
                        <div class="tiny muted">{{ $peak->sampled_at->format('d/m H:i') }}</div>
                    </div>
                    <div style="flex:1">
                        <div class="k muted tiny">Proceso que más CPU consumía en ese momento</div>
                        <div style="font-family:ui-monospace,monospace;font-size:13px;word-break:break-all">
                            {{ $peak->top_cpu_cmd ?? '—' }} @if($peak->top_cpu_pct) <span style="color:#fca5a5">({{ $peak->top_cpu_pct }}%)</span> @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Gráficas --}}
        @foreach($charts as [$key, $label, $color, $accessor])
            @php($pts = $buildPoints($accessor))
            @php($last = $samples->last())
            @php($cur = $key === 'cpu' ? $last->cpu_pct : ($key === 'mem' ? $last->memPct() : $last->diskPct()))
            <h2><span class="section-ic" style="background:{{ $color }}22;border-color:{{ $color }}55">
                {{ ['cpu'=>'🔥','mem'=>'🧠','disk'=>'💾'][$key] }}</span>
                {{ $label }} <span class="muted tiny" style="font-weight:400">· ahora {{ $cur !== null ? $cur.'%' : '—' }}</span>
            </h2>
            <div class="card" style="padding:12px">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" style="width:100%;height:180px;display:block">
                    {{-- Líneas de referencia 25/50/75/100 --}}
                    @foreach([0,25,50,75,100] as $g)
                        @php($gy = $padT + ($H - $padT - $padB) * (1 - $g/100))
                        <line x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $W - $padR }}" y2="{{ $gy }}" stroke="#26324f" stroke-width="1" stroke-dasharray="{{ $g===0||$g===100 ? '0' : '3 4' }}"/>
                        <text x="{{ $W - $padR - 2 }}" y="{{ $gy - 2 }}" fill="#64748b" font-size="10" text-anchor="end">{{ $g }}%</text>
                    @endforeach
                    <polygon points="{{ $pts['area'] }}" fill="{{ $color }}" fill-opacity="0.12"/>
                    <polyline points="{{ $pts['line'] }}" fill="none" stroke="{{ $color }}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                </svg>
                <div class="row" style="justify-content:space-between;margin-top:4px">
                    <span class="muted tiny">{{ $samples->first()->sampled_at->format('d/m H:i') }}</span>
                    <span class="muted tiny">{{ $samples->last()->sampled_at->format('d/m H:i') }}</span>
                </div>
            </div>
        @endforeach

        {{-- Momentos de mayor CPU --}}
        <h2><span class="section-ic">🚨</span> Momentos de mayor CPU</h2>
        <div class="list-card">
            <table>
                <thead><tr><th>Cuándo</th><th>CPU</th><th>RAM</th><th>Carga</th><th>Proceso top</th></tr></thead>
                <tbody>
                @foreach($samples->sortByDesc('cpu_pct')->take(10) as $s)
                    <tr>
                        <td class="tiny">{{ $s->sampled_at->format('d/m H:i') }}</td>
                        <td style="font-weight:700;color:{{ $s->cpu_pct >= 85 ? 'var(--bad)' : ($s->cpu_pct >= 60 ? 'var(--warn)' : 'var(--text)') }}">{{ $s->cpu_pct }}%</td>
                        <td>{{ $s->memPct() }}%</td>
                        <td class="muted">{{ $s->load1 }}</td>
                        <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $s->top_cpu_cmd }} @if($s->top_cpu_pct)({{ $s->top_cpu_pct }}%)@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
