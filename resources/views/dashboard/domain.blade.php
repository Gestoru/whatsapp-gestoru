@extends('dashboard.layout')
@section('title', $domain.' · '.config('dashboard.title'))
@section('subtitle', 'reporte del dominio')

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
@endsection

@section('content')
    <div class="row" style="justify-content:space-between;margin-bottom:4px">
        <h1>🌐 {{ $domain }}</h1>
        <span class="pill">{{ $server->name }} · {{ $server->host }}</span>
    </div>

    @if($error)
        <div class="alert alert-bad" style="margin-top:12px">{{ $error }}</div>
    @elseif($report)
        @if($site && $site['root'])
            <p class="muted tiny" style="margin:4px 0 0">Carpeta del proyecto: <span style="font-family:ui-monospace,monospace">{{ $site['root'] }}</span></p>
        @endif

        @unless($report['log_exists'])
            <div class="alert" style="background:#2e2410;border-color:#6b5316;color:#fcd34d;margin-top:12px">
                No encontré registros en <span style="font-family:ui-monospace,monospace">{{ $site['access_log'] }}</span> — el reporte de tráfico estará vacío hasta que el sitio reciba visitas o se ajuste la ruta del log.
            </div>
        @endunless

        {{-- ── Tráfico y usuarios ── --}}
        <h2><span class="section-ic">👥</span> Tráfico y usuarios</h2>
        <div class="stat-grid">
            <div class="stat"><div class="k">Usuarios última hora</div><div class="v">{{ number_format($report['hour_ips']) }}</div><div class="k tiny">IPs únicas</div></div>
            <div class="stat"><div class="k">Peticiones última hora</div><div class="v">{{ number_format($report['hour_requests']) }}</div></div>
            <div class="stat"><div class="k">Usuarios hoy</div><div class="v">{{ number_format($report['today_ips']) }}</div><div class="k tiny">IPs únicas</div></div>
            <div class="stat"><div class="k">Peticiones hoy</div><div class="v">{{ number_format($report['today_requests']) }}</div></div>
        </div>

        <div class="grid" style="grid-template-columns:1fr 1.4fr;gap:16px;margin-top:16px">
            {{-- Códigos de estado --}}
            <div class="list-card">
                <div class="fb-head">Respuestas de hoy (códigos)</div>
                @if(empty($report['status_codes']))
                    <div class="empty" style="padding:24px"><span class="muted tiny">Sin datos hoy</span></div>
                @else
                    <table>
                        <tbody>
                        @foreach($report['status_codes'] as $row)
                            @php($code = $row['value'])
                            @php($tone = str_starts_with($code,'5') ? 'var(--bad)' : (str_starts_with($code,'4') ? 'var(--warn)' : 'var(--ok)'))
                            <tr>
                                <td><span style="color:{{ $tone }};font-weight:700">{{ $code }}</span></td>
                                <td class="muted tiny">{{ str_starts_with($code,'5') ? 'error del servidor' : (str_starts_with($code,'4') ? 'error del cliente' : 'correcto') }}</td>
                                <td style="text-align:right;font-weight:600">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Rutas top --}}
            <div class="list-card">
                <div class="fb-head">Rutas más pedidas hoy</div>
                @if(empty($report['top_paths']))
                    <div class="empty" style="padding:24px"><span class="muted tiny">Sin datos hoy</span></div>
                @else
                    <table>
                        <tbody>
                        @foreach($report['top_paths'] as $row)
                            <tr>
                                <td style="font-family:ui-monospace,monospace;font-size:13px;word-break:break-all">{{ $row['value'] }}</td>
                                <td style="text-align:right;font-weight:600;white-space:nowrap">{{ number_format($row['count']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ── Errores ── --}}
        <h2><span class="section-ic">🚨</span> Últimos errores del servidor web (5xx)</h2>
        <div class="list-card fb-file">
            @if(empty($report['last_5xx']))
                <div class="empty" style="padding:24px"><span class="muted tiny">Sin errores 5xx en el registro reciente 🎉</span></div>
            @else
                <pre style="max-height:260px">{{ implode("\n", $report['last_5xx']) }}</pre>
            @endif
        </div>

        <h2><span class="section-ic">📋</span> Error log de nginx</h2>
        <div class="list-card fb-file">
            @if(empty($report['error_log']))
                <div class="empty" style="padding:24px"><span class="muted tiny">Error log vacío o sin acceso</span></div>
            @else
                <pre style="max-height:260px">{{ implode("\n", $report['error_log']) }}</pre>
            @endif
        </div>

        <h2><span class="section-ic">🧩</span> Log de la aplicación</h2>
        <div class="list-card fb-file">
            @if(empty($report['app_log']))
                <div class="empty" style="padding:24px"><span class="muted tiny">No se detectó un log de aplicación (Laravel) para este dominio</span></div>
            @else
                <pre style="max-height:300px">{{ implode("\n", $report['app_log']) }}</pre>
            @endif
        </div>
    @endif
@endsection
