{{-- Reporte analítico en vivo: se carga por AJAX dentro del panel del servidor --}}
@if($error)
    <div class="alert alert-bad" style="margin-top:14px">{{ $error }}</div>
@elseif($report)
    {{-- ══════════ MYSQL PRIMERO: lo más crítico del servidor ══════════ --}}
    <h2><span class="section-ic" style="background:#a78bfa22;border-color:#a78bfa66;color:#a78bfa">🗄️</span> Base de datos MySQL / MariaDB
        @if(!empty($report['mysql_via']))<span class="muted tiny" style="font-weight:400">· leído vía {{ $report['mysql_via'] }}</span>@endif
        <a href="{{ route('dashboard.servers.queries', $server) }}" class="btn btn-sm" style="margin-left:auto">🧠 Optimizar consultas con IA</a>
    </h2>
    @if(!$report['mysql_available'])
        <div class="list-card" style="padding:16px">
            <span class="muted tiny">No pude consultar MySQL en el host ni en contenedores Docker. Puede que la base de datos esté protegida con contraseña que no está en las variables del contenedor, o que use otro motor. Mándame un pantallazo y lo ajustamos.</span>
        </div>
    @else
        {{-- ⚡ Consultas ejecutándose AHORA (en vivo, con las colgadas resaltadas) --}}
        <div class="card" style="padding:0;overflow:hidden;border:1px solid #a78bfa55;margin-bottom:16px" data-mysql-live="{{ route('dashboard.servers.mysql.live', $server) }}">
            <div class="row" style="justify-content:space-between;padding:12px 16px;background:linear-gradient(90deg,#a78bfa1f,transparent)">
                <span style="font-weight:700;font-size:15px">⚡ Consultas ejecutándose ahora mismo</span>
                <span class="tiny" id="mysql-live-meta" style="color:#22e39b;font-weight:600">🔴 en vivo · se actualiza cada 10 s</span>
            </div>
            <div class="stat-grid" style="padding:0 14px 12px;gap:8px;grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
                <div class="stat" style="padding:9px 12px"><div class="k">Conexiones ahora</div><div class="v" id="ml-conns">—</div></div>
                <div class="stat" style="padding:9px 12px"><div class="k">Ejecutándose</div><div class="v" id="ml-running">—</div></div>
                <div class="stat" style="padding:9px 12px"><div class="k">La más larga ahora</div><div class="v" id="ml-longest">—</div></div>
                <div class="stat" style="padding:9px 12px"><div class="k">⚠️ Colgadas (≥5 s)</div><div class="v" id="ml-stuck" style="color:var(--ok)">—</div></div>
            </div>
            <div class="ml-body">
                @if(empty($report['mysql_processes']))
                    <div class="empty" style="padding:20px"><span class="muted tiny">Ninguna consulta pesada en curso 🎉</span></div>
                @else
                    <table>
                        <thead><tr><th>Seg</th><th>BD</th><th>Usuario</th><th>Consulta</th></tr></thead>
                        <tbody>
                        @foreach($report['mysql_processes'] as $q)
                            <tr>
                                <td style="font-weight:700;color:{{ (int)$q['time'] >= 5 ? 'var(--bad)' : 'var(--text)' }}">{{ $q['time'] }}@if((int)$q['time'] >= 5) ⚠️@endif</td>
                                <td class="muted tiny">{{ $q['db'] }}</td>
                                <td class="muted tiny">{{ $q['user'] ?? '-' }}</td>
                                <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ $q['info'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- 🏆 Las que MÁS han consumido (histórico acumulado) --}}
        <h3 style="font-size:14px;margin:6px 0 10px;color:var(--muted)">🏆 Consultas que MÁS han consumido (acumulado desde el último reinicio de MySQL) · <a href="{{ route('dashboard.servers.queries', $server) }}" style="color:var(--accent)">ver el detalle completo con diagnóstico</a></h3>
        <div class="list-card" style="margin-bottom:16px">
            @if(empty($report['mysql_top']))
                <div class="empty" style="padding:20px"><span class="muted tiny">performance_schema no tiene datos aún (o está desactivado). Esta tabla se llena sola con el uso.</span></div>
            @else
                @php($maxTot = max(array_map(fn ($x) => (float) ($x['total_s'] ?? 0), $report['mysql_top'])) ?: 1)
                <div class="qtop">
                    @foreach($report['mysql_top'] as $i => $q)
                        @php($tot = (float) $q['total_s'])
                        @php($pct = max(3, round($tot / $maxTot * 100)))
                        @php($totColor = $tot >= 60 ? 'var(--bad)' : ($tot >= 10 ? 'var(--warn)' : '#22e39b'))
                        @php($avg = (float) $q['avg_ms'])
                        @php($avgColor = $avg >= 1000 ? 'var(--bad)' : ($avg >= 300 ? 'var(--warn)' : 'var(--muted)'))
                        <div class="qtop-row">
                            <div class="qtop-n">{{ $i + 1 }}</div>
                            <div class="qtop-body">
                                <div class="qtop-meta">
                                    <span class="qtop-total" style="color:{{ $totColor }}">{{ $q['total_s'] }}s <span class="qtop-lbl">acumulado</span></span>
                                    <span class="qtop-db">🗄️ {{ $q['db'] }}</span>
                                    <span class="qtop-sep">·</span>
                                    <span>{{ number_format((int) $q['execs']) }} ejec</span>
                                    <span class="qtop-sep">·</span>
                                    <span style="color:{{ $avgColor }}">{{ $avg >= 1000 ? round($avg / 1000, 1).'s' : $q['avg_ms'].'ms' }} prom</span>
                                    <span class="qtop-when">🕓 {{ $q['last_seen'] ?? '—' }}</span>
                                </div>
                                <div class="qtop-bar"><span style="width:{{ $pct }}%;background:{{ $totColor }}"></span></div>
                                <code class="qtop-sql" title="Clic para ver la consulta completa">{{ $q['query'] }}</code>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="muted tiny" style="margin:10px 2px 0">💡 La barra muestra cuánto pesa cada consulta frente a la más pesada. Clic en una consulta para verla completa · <a href="{{ route('dashboard.servers.queries', $server) }}" style="color:var(--accent)">abrir el diagnóstico con la estructura de tablas</a>.</p>
            @endif
        </div>

        {{-- Tamaño de las bases de datos --}}
        <div class="list-card" style="margin-bottom:4px">
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
    @endif

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
            <div class="empty" style="padding:20px"><span class="muted tiny">No hay tráfico registrado hoy (ni en los logs de nginx del host ni en los contenedores Docker)</span></div>
        @else
            <table>
                <thead><tr><th>Dominio / contenedor</th><th style="text-align:right">Ancho de banda</th><th style="text-align:right">Peticiones</th><th style="text-align:right">IPs únicas</th></tr></thead>
                <tbody>
                @foreach($report['domains'] as $d)
                    <tr>
                        <td style="font-weight:600">{{ str_starts_with($d['label'], '🐳') ? '' : '🌐 ' }}{{ $d['label'] }}</td>
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
@endif
