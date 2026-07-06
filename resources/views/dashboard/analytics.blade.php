@extends('dashboard.layout')
@section('title', 'Análisis · '.$server->name)
@section('subtitle', 'reporte analítico de '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
    <button onclick="location.reload()" class="btn btn-sm">🔄 Actualizar</button>
@endsection

@section('content')
    <h1 style="margin-bottom:4px">📈 Análisis de {{ $server->name }}</h1>
    <p class="muted tiny">Foto del servidor en este momento · todo de solo lectura</p>

    @if($error)
        <div class="alert alert-bad" style="margin-top:14px">{{ $error }}</div>
    @elseif($report)
        {{-- ── Resumen del sistema ── --}}
        @if(!empty($report['top_summary']))
            <h2><span class="section-ic">🧭</span> Estado del sistema</h2>
            <div class="list-card fb-file"><pre style="max-height:150px">{{ implode("\n", $report['top_summary']) }}</pre></div>
        @endif

        {{-- ── CPU ── --}}
        <h2><span class="section-ic">🔥</span> Qué consume la CPU</h2>
        <div class="list-card">
            @if(empty($report['cpu']))
                <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
            @else
                <table>
                    <thead><tr><th>%CPU</th><th>%MEM</th><th>PID</th><th>Usuario</th><th>Proceso</th></tr></thead>
                    <tbody>
                    @foreach($report['cpu'] as $p)
                        <tr>
                            <td style="font-weight:700;color:{{ (float)$p['cpu'] >= 50 ? 'var(--bad)' : ((float)$p['cpu'] >= 20 ? 'var(--warn)' : 'var(--text)') }}">{{ $p['cpu'] }}</td>
                            <td>{{ $p['mem'] }}</td>
                            <td class="muted tiny">{{ $p['pid'] }}</td>
                            <td class="muted tiny">{{ $p['user'] }}</td>
                            <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $p['command'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── RAM ── --}}
        <h2><span class="section-ic">🧠</span> Qué consume la memoria RAM</h2>
        <div class="grid" style="grid-template-columns:1.3fr 1fr;gap:16px">
            <div class="list-card">
                <div class="fb-head">Procesos por memoria</div>
                @if(empty($report['mem']))
                    <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
                @else
                    <table>
                        <thead><tr><th>%MEM</th><th>%CPU</th><th>Proceso</th></tr></thead>
                        <tbody>
                        @foreach($report['mem'] as $p)
                            <tr>
                                <td style="font-weight:700;color:{{ (float)$p['mem'] >= 20 ? 'var(--warn)' : 'var(--text)' }}">{{ $p['mem'] }}</td>
                                <td class="muted">{{ $p['cpu'] }}</td>
                                <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $p['command'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
            <div class="list-card">
                <div class="fb-head">Memoria por programa (sumada)</div>
                @if(empty($report['mem_by_prog']))
                    <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
                @else
                    <table>
                        <tbody>
                        @foreach($report['mem_by_prog'] as $m)
                            <tr>
                                <td style="font-family:ui-monospace,monospace">{{ $m['program'] }}</td>
                                <td style="text-align:right;font-weight:600">{{ $m['human'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ── Ancho de banda por dominio ── --}}
        <h2><span class="section-ic">📊</span> Ancho de banda y peticiones por dominio <span class="muted tiny" style="font-weight:400">(hoy)</span></h2>
        <div class="stat-grid" style="margin-bottom:12px">
            <div class="stat"><div class="k">Peticiones totales hoy</div><div class="v">{{ number_format($report['total_requests']) }}</div></div>
            <div class="stat"><div class="k">Ancho de banda hoy</div><div class="v">{{ $report['total_bandwidth'] }}</div></div>
            <div class="stat"><div class="k">Dominios con tráfico</div><div class="v">{{ count($report['domains']) }}</div></div>
        </div>
        <div class="list-card">
            @if(empty($report['domains']))
                <div class="empty" style="padding:20px"><span class="muted tiny">No hay tráfico registrado hoy en los logs de nginx</span></div>
            @else
                <table>
                    <thead><tr><th>Dominio</th><th style="text-align:right">Ancho de banda</th><th style="text-align:right">Peticiones</th><th style="text-align:right">IPs únicas</th></tr></thead>
                    <tbody>
                    @foreach($report['domains'] as $d)
                        <tr>
                            <td style="font-weight:600">🌐 {{ $d['label'] }}</td>
                            <td style="text-align:right;font-weight:700">{{ $d['human'] }}</td>
                            <td style="text-align:right">{{ number_format($d['requests']) }}</td>
                            <td style="text-align:right" class="muted">{{ number_format($d['ips']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Rutas más pesadas ── --}}
        <h2><span class="section-ic">🏋️</span> Peticiones que más ancho de banda consumen <span class="muted tiny" style="font-weight:400">(hoy, todos los dominios)</span></h2>
        <div class="list-card">
            @if(empty($report['heavy_paths']))
                <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
            @else
                <table>
                    <thead><tr><th>Ruta</th><th style="text-align:right">Ancho de banda</th><th style="text-align:right">Peticiones</th></tr></thead>
                    <tbody>
                    @foreach($report['heavy_paths'] as $h)
                        <tr>
                            <td style="font-family:ui-monospace,monospace;font-size:13px;word-break:break-all">{{ $h['path'] }}</td>
                            <td style="text-align:right;font-weight:700">{{ $h['human'] }}</td>
                            <td style="text-align:right" class="muted">{{ number_format($h['count']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Conexiones de red ── --}}
        <h2><span class="section-ic">🌐</span> Conexiones de red</h2>
        <div class="grid" style="grid-template-columns:1fr 1.3fr;gap:16px">
            <div class="list-card">
                <div class="fb-head">Conexiones por estado</div>
                @if(empty($report['connections']))
                    <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
                @else
                    <table><tbody>
                        @foreach($report['connections'] as $c)
                            <tr><td>{{ $c['value'] }}</td><td style="text-align:right;font-weight:600">{{ number_format($c['count']) }}</td></tr>
                        @endforeach
                    </tbody></table>
                @endif
            </div>
            <div class="list-card">
                <div class="fb-head">IPs con más conexiones activas</div>
                @if(empty($report['top_ips']))
                    <div class="empty" style="padding:20px"><span class="muted tiny">Sin conexiones establecidas</span></div>
                @else
                    <table><tbody>
                        @foreach($report['top_ips'] as $c)
                            <tr><td style="font-family:ui-monospace,monospace">{{ $c['value'] }}</td><td style="text-align:right;font-weight:600">{{ number_format($c['count']) }}</td></tr>
                        @endforeach
                    </tbody></table>
                @endif
            </div>
        </div>

        {{-- ── MySQL ── --}}
        <h2><span class="section-ic">🗄️</span> Base de datos MySQL / MariaDB</h2>
        @if(!$report['mysql_available'])
            <div class="list-card" style="padding:16px">
                <span class="muted tiny">No pude consultar MySQL (o no está instalado, o el usuario root del sistema no tiene acceso directo). Si quieres el detalle de consultas y tamaño de bases de datos, lo configuramos en la Fase 2.</span>
            </div>
        @else
            <div class="grid" style="grid-template-columns:1fr 1fr;gap:16px">
                <div class="list-card">
                    <div class="fb-head">Consultas activas ahora</div>
                    @if(empty($report['mysql_processes']))
                        <div class="empty" style="padding:20px"><span class="muted tiny">Ninguna consulta pesada en curso 🎉</span></div>
                    @else
                        <table>
                            <thead><tr><th>Seg</th><th>BD</th><th>Consulta</th></tr></thead>
                            <tbody>
                            @foreach($report['mysql_processes'] as $q)
                                <tr>
                                    <td style="font-weight:700;color:{{ (int)$q['time'] >= 5 ? 'var(--bad)' : 'var(--text)' }}">{{ $q['time'] }}</td>
                                    <td class="muted tiny">{{ $q['db'] }}</td>
                                    <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $q['info'] }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                <div class="list-card">
                    <div class="fb-head">Tamaño de las bases de datos</div>
                    @if(empty($report['mysql_databases']))
                        <div class="empty" style="padding:20px"><span class="muted tiny">Sin datos</span></div>
                    @else
                        <table><tbody>
                            @foreach($report['mysql_databases'] as $db)
                                <tr><td style="font-family:ui-monospace,monospace">{{ $db['db'] }}</td><td style="text-align:right;font-weight:600">{{ $db['mb'] }} MB</td></tr>
                            @endforeach
                        </tbody></table>
                    @endif
                </div>
            </div>
        @endif

        <p class="muted tiny" style="margin-top:20px">💡 Este reporte es una foto del momento. Para ver tendencias en el tiempo (histórico de CPU/RAM y picos), lo agregamos en la Fase 2 con guardado periódico.</p>
    @endif
@endsection
