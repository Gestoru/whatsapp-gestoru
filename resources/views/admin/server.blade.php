@extends('admin.layout')
@section('title', $server['name'])

@section('content')
    <div class="toolbar">
        <a href="{{ route('admin.dashboard') }}" class="muted">← Servidores</a>
        <div class="spacer"></div>
        <a href="{{ route('admin.server.folders', $server['key']) }}" class="btn sm ghost">Explorar carpetas</a>
        <a href="{{ route('admin.server.show', $server['key']) }}" class="btn sm">↻ Actualizar</a>
    </div>

    <div class="row" style="margin-bottom:6px;">
        <h1>{{ $server['name'] }}</h1>
        <span class="tag {{ $server['group'] }}">{{ $server['group'] }}</span>
    </div>
    <p class="sub mono">{{ $server['username'] }}@{{ $server['host'] }}:{{ $server['port'] }}</p>

    @if ($error)
        <div class="alert error">
            <strong>No se pudo conectar:</strong> {{ $error }}
        </div>
        <div class="alert info">
            Revisa que la IP/puerto sean correctos, que la llave o contraseña estén bien configuradas
            y que tu servidor permita conexiones SSH desde esta red.
        </div>
    @else
        @php $ov = $overview; @endphp

        {{-- Sistema --}}
        <div class="panel" style="margin-bottom:16px;">
            <h2>Sistema</h2>
            <div class="grid metrics">
                <div><div class="metric-label">Hostname</div><div class="mono">{{ $ov['system']['hostname'] ?: '—' }}</div></div>
                <div><div class="metric-label">Sistema operativo</div><div>{{ $ov['system']['os'] ?: '—' }}</div></div>
                <div><div class="metric-label">Kernel</div><div class="mono">{{ $ov['system']['kernel'] ?: '—' }}</div></div>
                <div><div class="metric-label">Uptime</div><div>{{ $ov['system']['uptime'] ?: '—' }}</div></div>
                <div><div class="metric-label">Núcleos CPU</div><div>{{ $ov['system']['cores'] ?: '—' }}</div></div>
            </div>
        </div>

        {{-- Métricas principales --}}
        <div class="grid metrics" style="margin-bottom:16px;">
            {{-- CPU --}}
            @php
                $cpuPct = $ov['cpu']['usage_pct'] ?? $ov['cpu']['load_pct'];
                $cpuClass = $cpuPct >= 85 ? 'danger' : ($cpuPct >= 60 ? 'warn' : '');
            @endphp
            <div class="panel">
                <div class="metric-label">CPU {{ isset($ov['cpu']['usage_pct']) ? '(uso)' : '(carga)' }}</div>
                <div class="metric-value">{{ $cpuPct }}%</div>
                <div class="bar {{ $cpuClass }}"><span style="width:{{ min(100,$cpuPct) }}%"></span></div>
                <div class="muted" style="margin-top:8px; font-size:12px;">
                    Load: {{ implode(' · ', array_map(fn($l) => number_format($l, 2), $ov['cpu']['load'])) }}
                    ({{ $ov['cpu']['cores'] }} núcleos)
                </div>
            </div>

            {{-- Memoria --}}
            @php
                $ram = $ov['memory']['ram'];
                $ramClass = $ram['pct'] >= 90 ? 'danger' : ($ram['pct'] >= 70 ? 'warn' : '');
            @endphp
            <div class="panel">
                <div class="metric-label">Memoria RAM</div>
                <div class="metric-value">{{ $ram['pct'] }}%</div>
                <div class="bar {{ $ramClass }}"><span style="width:{{ min(100,$ram['pct']) }}%"></span></div>
                <div class="muted" style="margin-top:8px; font-size:12px;">
                    {{ number_format($ram['used']) }} / {{ number_format($ram['total']) }} MB usados
                    @if ($ov['memory']['swap']['total'] > 0)
                        · Swap {{ number_format($ov['memory']['swap']['used']) }}/{{ number_format($ov['memory']['swap']['total']) }} MB
                    @endif
                </div>
            </div>
        </div>

        {{-- Disco --}}
        <div class="panel" style="margin-bottom:16px;">
            <h2>Almacenamiento</h2>
            @if (empty($ov['disk']))
                <p class="muted">No se pudo leer el uso de disco.</p>
            @else
                <table>
                    <thead><tr><th>Sistema de archivos</th><th>Montaje</th><th>Tamaño</th><th>Usado</th><th>Libre</th><th style="width:180px;">Uso</th></tr></thead>
                    <tbody>
                    @foreach ($ov['disk'] as $d)
                        @php $dc = $d['pct'] >= 90 ? 'danger' : ($d['pct'] >= 75 ? 'warn' : ''); @endphp
                        <tr>
                            <td class="mono">{{ $d['filesystem'] }}</td>
                            <td class="mono">{{ $d['mount'] }}</td>
                            <td>{{ $d['size'] }}</td>
                            <td>{{ $d['used'] }}</td>
                            <td>{{ $d['available'] }}</td>
                            <td>
                                <div class="row" style="gap:8px;">
                                    <div class="bar {{ $dc }}" style="flex:1;"><span style="width:{{ min(100,$d['pct']) }}%"></span></div>
                                    <span style="width:38px; text-align:right;">{{ $d['pct'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Procesos --}}
        <div class="panel">
            <h2>Procesos con más CPU</h2>
            @if (empty($ov['cpu']['processes']))
                <p class="muted">No se pudo leer la lista de procesos.</p>
            @else
                <table>
                    <thead><tr><th>PID</th><th>Proceso</th><th>CPU %</th><th>Memoria %</th></tr></thead>
                    <tbody>
                    @foreach ($ov['cpu']['processes'] as $p)
                        <tr>
                            <td class="mono">{{ $p['pid'] }}</td>
                            <td class="mono">{{ $p['name'] }}</td>
                            <td>{{ number_format($p['cpu'], 1) }}%</td>
                            <td>{{ number_format($p['mem'], 1) }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
@endsection
