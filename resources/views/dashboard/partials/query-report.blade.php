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

    {{-- ── Consultas RESUELTAS (ya no aparecen en el ranking) ── --}}
    @if(!empty($resolved))
        <details class="card" style="margin:14px 0 4px;padding:0;border-left:3px solid var(--ok);overflow:hidden">
            <summary style="cursor:pointer;padding:12px 16px;font-weight:700;color:#86efac;list-style:none">
                ✅ {{ count($resolved) }} consulta(s) que antes estaban en el ranking y ya NO aparecen <span class="muted tiny" style="font-weight:400">— posiblemente optimizadas · clic para ver</span>
            </summary>
            <div style="padding:0 12px 12px">
                <table>
                    <thead><tr><th>BD</th><th style="text-align:right">Antes: tiempo</th><th style="text-align:right">Antes: promedio</th><th style="text-align:right">Vista por última vez</th><th>Consulta</th></tr></thead>
                    <tbody>
                    @foreach($resolved as $r)
                        <tr>
                            <td class="muted tiny">{{ $r['db'] }}</td>
                            <td style="text-align:right">{{ number_format((int) $r['total_s']) }} s</td>
                            <td style="text-align:right">{{ round($r['avg_ms']) }} ms</td>
                            <td class="muted tiny" style="text-align:right;white-space:nowrap">{{ $r['last_seen']->format('d/m H:i') }}</td>
                            <td class="tiny" style="font-family:ui-monospace,monospace;word-break:break-all">{{ \Illuminate\Support\Str::limit($r['query'], 90) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    {{-- ── Tarjetas por consulta ── --}}
    <h2><span class="section-ic">🏆</span> Las {{ count($queries) }} consultas que más carga generan <span class="muted tiny" style="font-weight:400">· ordenadas por tiempo total consumido</span></h2>
    @if(empty($queries))
        <div class="list-card" style="padding:18px"><span class="muted tiny">performance_schema no tiene datos aún (o está desactivado). Se llena solo con el uso.</span></div>
    @endif

    @php
        $trendMeta = [
            'nueva'      => ['#a78bfa', '#a78bfa22'],
            'mejorando'  => ['#22e39b', '#22e39b22'],
            'empeorando' => ['#ff4d6d', '#ff4d6d22'],
            'estable'    => ['#94a3c4', '#94a3c422'],
        ];
    @endphp

    @foreach($queries as $q)
        @php([$sevColor, $sevLabel] = $sevMeta[$q['severity']] ?? $sevMeta['media'])
        @php([$trColor, $trBg] = $trendMeta[$q['trend']['state']] ?? $trendMeta['estable'])
        <div class="card" style="margin-bottom:16px;border-left:3px solid {{ $sevColor }};padding:16px 18px">
            <div class="row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
                <div class="row" style="gap:8px">
                    <span style="font-size:18px;font-weight:800;color:{{ $sevColor }}">#{{ $q['rank'] }}</span>
                    <span class="tag" style="background:{{ $sevColor }}22;color:{{ $sevColor }}">{{ $sevLabel }}</span>
                    <span class="tag" style="background:{{ $trBg }};color:{{ $trColor }}" title="{{ $q['trend']['since'] ? 'comparado con hace '.$q['trend']['since'] : 'primera vez que se registra' }}">{{ $q['trend']['label'] }}</span>
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

            {{-- ── Detalle técnico colapsable: estructura de tablas + contexto ── --}}
            <details style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px">
                <summary style="cursor:pointer;font-weight:600;font-size:13px;color:#7dd3fc;list-style:none">🔬 Ver detalle técnico (estructura de tablas y contexto que genera este reporte)</summary>
                <div style="margin-top:10px">
                    <div class="tiny muted" style="margin-bottom:6px">Esta consulta se detecta leyendo <code>performance_schema.events_statements_summary_by_digest</code> (el resumen que MySQL lleva de cada tipo de consulta). Los <code>?</code> son los parámetros que cambian en cada ejecución.</div>
                    @if(!empty($q['tables']))
                        @foreach($q['tables'] as $t)
                            <div style="margin-top:10px">
                                <div class="tiny" style="font-weight:700;font-family:ui-monospace,monospace;color:#a5b4fc">
                                    🗄️ {{ $t }}@if(isset($report['ddl'][$t]['rows'])) <span class="muted" style="font-weight:400">· {{ number_format($report['ddl'][$t]['rows']) }} filas @if(isset($report['ddl'][$t]['mb']))· {{ $report['ddl'][$t]['mb'] }} MB @endif</span>@endif
                                </div>
                                @if(isset($report['ddl'][$t]['create']))
                                    <div class="fb-file" style="margin-top:4px;border:1px solid var(--line);border-radius:8px">
                                        <pre style="max-height:200px;font-size:11.5px">{{ $report['ddl'][$t]['create'] }}</pre>
                                    </div>
                                @else
                                    <div class="tiny muted">No se pudo leer la estructura de esta tabla.</div>
                                @endif
                            </div>
                        @endforeach
                    @else
                        <div class="tiny muted">No se identificaron las tablas automáticamente para esta consulta.</div>
                    @endif
                </div>
            </details>

            <div class="row" style="justify-content:space-between;margin-top:14px;flex-wrap:wrap;gap:8px">
                <span class="muted tiny">🕓 Vista del {{ $q['first_seen_local'] ?? $q['first_seen'] }} al {{ $q['last_seen_local'] ?? $q['last_seen'] }} <span style="opacity:.7">(hora Colombia)</span></span>
                <div class="row" style="gap:6px">
                    <button type="button" class="btn btn-primary btn-sm" data-copy="ai-ctx-{{ $q['rank'] }}">🤖 Copiar contexto para IA</button>
                    <button type="button" class="btn btn-sm" data-copy="ai-fix-{{ $q['rank'] }}" title="Requiere conectar GitHub para aplicar el cambio automáticamente (próximamente)">🔧 Preparar arreglo</button>
                </div>
            </div>
            <textarea id="ai-ctx-{{ $q['rank'] }}" readonly style="display:none">{{ $q['ai_prompt'] }}</textarea>
            <textarea id="ai-fix-{{ $q['rank'] }}" readonly style="display:none">{{ $q['ai_prompt'] }}

## Formato de respuesta que necesito para aplicarlo por GitHub
Devuélveme el arreglo como un PLAN APLICABLE:
1. Si es un índice: la migración de Laravel completa (archivo database/migrations/xxxx_add_index.php) lista para commitear.
2. Si es un cambio de consulta o de código: el archivo exacto a tocar y el diff (antes/después).
3. Un título corto para el commit y una explicación de una línea para el PR.
Se conectará GitHub para abrir el Pull Request con tu propuesta.</textarea>
        </div>
    @endforeach

    <p class="muted tiny" style="margin-top:16px">💡 <b>Cómo usarlo:</b> «🤖 Copiar contexto para IA» copia todo (consulta, estadísticas, usuario y estructura de tablas) para pegar en ChatGPT/Claude y recibir índices y cambios concretos. «🔧 Preparar arreglo» copia lo mismo pidiendo el resultado en formato aplicable (migración/diff) — cuando conectes GitHub, el panel podrá abrir el Pull Request con la propuesta. Las etiquetas <b>✅ mejoró / ⚠️ empeoró / ● estable</b> comparan cada consulta con su estado anterior para darle seguimiento.</p>
@endif
