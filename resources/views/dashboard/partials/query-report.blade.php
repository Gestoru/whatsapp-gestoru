{{-- Optimizador de consultas: se carga por AJAX dentro de dashboard.queries --}}
@if($error)
    <div class="alert alert-bad" style="margin-top:14px">{{ $error }}</div>
@elseif(!$report || !$report['available'])
    <div class="list-card" style="padding:18px;margin-top:14px">
        <span class="muted tiny">No pude consultar MySQL en el host ni en contenedores Docker de este servidor.</span>
    </div>
@else
    @php
        $sevMeta = [
            'critica' => ['#ff4d6d', '🔴 Crítica'],
            'alta'    => ['#fbbf24', '🟡 Alta'],
            'media'   => ['#38bdf8', '🔵 Media'],
        ];
        $totalS = array_sum(array_column($queries, 'total_s'));
    @endphp

    {{-- ── Resumen del motor ── --}}
    <div class="stat-grid" style="margin:14px 0 4px">
        <div class="stat"><div class="k">Motor</div><div class="v" style="font-size:14px">{{ $report['version'] ?? '—' }}</div></div>
        <div class="stat"><div class="k">Leído vía</div><div class="v" style="font-size:14px">{{ $report['via'] }}</div></div>
        <div class="stat"><div class="k">Uptime de MySQL</div><div class="v">{{ $report['uptime'] ? round($report['uptime']/86400, 1).' días' : '—' }}</div></div>
        <div class="stat"><div class="k">Buffer pool</div><div class="v">{{ $report['buffer'] ? round($report['buffer']/1073741824, 1).' GB' : '—' }}</div></div>
        <div class="stat"><div class="k">Carga total (top {{ count($queries) }})</div><div class="v">{{ number_format((int) $totalS) }} s</div></div>
    </div>

    {{-- ── Usuarios de la base de datos ── --}}
    @if(!empty($report['users']))
        <h2><span class="section-ic">👤</span> Actividad por usuario de MySQL <span class="muted tiny" style="font-weight:400">· acumulado desde el último reinicio</span></h2>
        <div class="list-card" style="margin-bottom:6px">
            <table>
                <thead><tr><th>Usuario</th><th style="text-align:right">Sentencias ejecutadas</th><th style="text-align:right">Tiempo total</th></tr></thead>
                <tbody>
                @foreach($report['users'] as $u)
                    <tr>
                        <td style="font-family:ui-monospace,monospace;font-weight:600">{{ $u['user'] }}</td>
                        <td style="text-align:right">{{ number_format((int) $u['execs']) }}</td>
                        <td style="text-align:right;font-weight:700">{{ number_format((float) $u['total_s'], 1) }} s</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="muted tiny" style="margin:6px 0 0">💡 El usuario por consulta individual sale en cada tarjeta de abajo (es una muestra de la actividad reciente: MySQL no guarda el usuario en el acumulado histórico).</p>
    @endif

    {{-- ── Tarjetas por consulta ── --}}
    <h2><span class="section-ic">🏆</span> Las {{ count($queries) }} consultas que más carga generan <span class="muted tiny" style="font-weight:400">· ordenadas por tiempo total consumido</span></h2>
    @if(empty($queries))
        <div class="list-card" style="padding:18px"><span class="muted tiny">performance_schema no tiene datos aún (o está desactivado). Se llena solo con el uso.</span></div>
    @endif

    @foreach($queries as $q)
        @php([$sevColor, $sevLabel] = $sevMeta[$q['severity']] ?? $sevMeta['media'])
        <div class="card" style="margin-bottom:16px;border-left:3px solid {{ $sevColor }};padding:16px 18px">
            <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div class="row" style="gap:8px">
                    <span style="font-size:18px;font-weight:800;color:{{ $sevColor }}">#{{ $q['rank'] }}</span>
                    <span class="tag" style="background:{{ $sevColor }}22;color:{{ $sevColor }}">{{ $sevLabel }}</span>
                    <span class="pill">🗄️ {{ $q['db'] }}</span>
                    <span class="pill">👤 {{ !empty($q['users']) ? implode(', ', $q['users']) : 'usuario no visto en muestra reciente' }}</span>
                </div>
                <div style="text-align:right">
                    <div style="font-size:19px;font-weight:800;color:{{ $sevColor }}">{{ $q['share'] }}% <span class="muted tiny" style="font-weight:400">de la carga</span></div>
                </div>
            </div>

            <div class="fb-file" style="margin:12px 0;border:1px solid var(--line);border-radius:10px">
                <pre style="max-height:110px;font-size:12.5px">{{ $q['query'] }}</pre>
            </div>

            <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(105px,1fr));gap:8px">
                <div class="stat" style="padding:9px 11px"><div class="k">Tiempo total</div><div class="v" style="font-size:15px">{{ number_format((int) $q['total_s']) }} s</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Ejecuciones</div><div class="v" style="font-size:15px">{{ number_format($q['execs']) }}</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Promedio</div><div class="v" style="font-size:15px;color:{{ $q['avg_ms'] >= 1000 ? 'var(--bad)' : ($q['avg_ms'] >= 300 ? 'var(--warn)' : 'var(--text)') }}">{{ $q['avg_ms'] >= 1000 ? round($q['avg_ms']/1000,1).' s' : $q['avg_ms'].' ms' }}</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Máximo</div><div class="v" style="font-size:15px">{{ $q['max_ms'] >= 1000 ? round($q['max_ms']/1000,1).' s' : $q['max_ms'].' ms' }}</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Filas exam./devueltas</div><div class="v" style="font-size:13px">{{ number_format($q['rows_examined']) }} / {{ number_format($q['rows_sent']) }}</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Sin índice</div><div class="v" style="font-size:15px;color:{{ $q['no_index_pct'] >= 30 ? 'var(--bad)' : 'var(--text)' }}">{{ $q['no_index_pct'] }}%</div></div>
                <div class="stat" style="padding:9px 11px"><div class="k">Temp. en disco</div><div class="v" style="font-size:15px">{{ number_format($q['tmp_disk']) }}</div></div>
            </div>

            <div style="margin-top:12px">
                <div class="tiny" style="font-weight:700;color:var(--muted);margin-bottom:6px">🩺 DIAGNÓSTICO AUTOMÁTICO</div>
                @foreach($q['findings'] as $fnd)
                    <div class="tiny" style="margin:4px 0;padding-left:14px;text-indent:-14px">• {{ $fnd }}</div>
                @endforeach
            </div>

            @if(!empty($q['tables']))
                <div class="row" style="gap:6px;margin-top:10px">
                    <span class="muted tiny">Tablas:</span>
                    @foreach($q['tables'] as $t)
                        <span class="pill tiny" style="font-family:ui-monospace,monospace">{{ $t }}@if(isset($report['ddl'][$t]['rows'])) · {{ number_format($report['ddl'][$t]['rows']) }} filas @endif</span>
                    @endforeach
                </div>
            @endif

            <div class="row" style="justify-content:space-between;margin-top:14px;flex-wrap:wrap;gap:8px">
                <span class="muted tiny">Vista del {{ $q['first_seen'] }} al {{ $q['last_seen'] }}</span>
                <button type="button" class="btn btn-primary btn-sm" data-copy="ai-ctx-{{ $q['rank'] }}">🤖 Copiar contexto para IA</button>
            </div>
            <textarea id="ai-ctx-{{ $q['rank'] }}" readonly style="display:none">{{ $q['ai_prompt'] }}</textarea>
        </div>
    @endforeach

    <p class="muted tiny" style="margin-top:16px">💡 <b>Cómo usarlo:</b> presiona «🤖 Copiar contexto para IA» en la consulta que quieras optimizar y pégalo en ChatGPT o Claude. El contexto incluye la consulta, sus estadísticas reales, el usuario que la ejecuta y la estructura de sus tablas — la IA te devolverá índices y cambios concretos, y te dirá qué información extra pedirle al panel si le falta algo.</p>
@endif
