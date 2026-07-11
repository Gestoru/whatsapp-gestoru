{{-- Columnas y tarjetas del tablero de rendimiento (se carga por AJAX).
     La tarjeta es compacta: solo el titular. El detalle completo
     (trazabilidad, métricas, consulta, causa, historial, contexto IA) vive
     en un modal para no saturar el tablero. --}}
@if($error)
    <div class="alert alert-bad" style="margin-top:14px">{{ $error }}</div>
@else
    @php
        $sevMeta = [
            'critica' => ['#ff4d6d', '🔴', 'Crítica'],
            'alta'    => ['#fbbf24', '🟡', 'Alta'],
            'media'   => ['#38bdf8', '🔵', 'Media'],
        ];
        $total = collect($board)->flatten(1)->count();
        $actions = [
            'por_revisar' => [['optimizando','🔧 Optimizar'], ['aceptada','🔒 No aplica']],
            'planeando'   => [['optimizando','🔧 Optimizar'], ['por_revisar','↩ Volver'], ['aceptada','🔒 No aplica']],
            'optimizando' => [['resuelta','✅ Resuelta'], ['aceptada','🔒 No aplica'], ['por_revisar','↩ Volver']],
            'resuelta'    => [['por_revisar','↩ Reabrir']],
            'aceptada'    => [['por_revisar','↩ Reabrir']],
        ];
    @endphp

    @if($total === 0)
        <div class="card empty" style="margin-top:14px">
            <div class="big">🎉</div>
            <h1>Sin incidencias de rendimiento</h1>
            <p class="muted" style="margin-top:8px">No hay picos de CPU ni consultas MySQL pesadas para clasificar. Cuando aparezcan, saldrán aquí como tarjetas.</p>
        </div>
    @else
        <div class="kboard-legend">
            @foreach($board as $col => $issues)
                @php([$ic,$lbl,$cc] = $columns[$col])
                <span style="color:{{ $cc }}">{{ $ic }} {{ $lbl }} <b>{{ count($issues) }}</b></span>
            @endforeach
        </div>

        <div class="kanban">
            @foreach($board as $col => $issues)
                @php([$ic,$lbl,$cc] = $columns[$col])
                <div class="kcol" data-col="{{ $col }}">
                    <div class="kcol-head" style="color:{{ $cc }}">{{ $ic }} {{ $lbl }} <span class="kcount">{{ count($issues) }}</span></div>
                    <div class="kcol-body">
                        @forelse($issues as $issue)
                            @php([$sc,$se,$sn] = $sevMeta[$issue->severity] ?? $sevMeta['media'])
                            @php($m = $issue->metrics ?? [])
                            @php($isMysql = $issue->kind === 'mysql_query')
                            <div class="kcard" data-id="{{ $issue->id }}" data-status="{{ $issue->status }}" data-kind="{{ $issue->kind }}" style="--sev:{{ $sc }}">
                                {{-- Titular compacto --}}
                                <div class="kc-head">
                                    <span class="kc-ic">{{ $isMysql ? '🗄️' : '🔥' }}</span>
                                    <span class="kc-title">{{ $issue->title }}</span>
                                    <span class="kc-sev" title="Severidad {{ $sn }}">{{ $se }}</span>
                                </div>

                                {{-- Métrica principal (una sola línea) --}}
                                <div class="kc-metric">
                                    @if($isMysql)
                                        <b class="kc-big">{{ number_format($m['total_s'] ?? 0) }}<span class="kc-unit">s</span></b>
                                        <span class="kc-sub">{{ number_format($m['execs'] ?? 0) }} ejec · {{ ($m['avg_ms'] ?? 0) >= 1000 ? round(($m['avg_ms'])/1000,1).'s' : ($m['avg_ms'] ?? 0).'ms' }} prom</span>
                                    @else
                                        <b class="kc-big">{{ $m['max_cpu'] ?? '?' }}<span class="kc-unit">%</span></b>
                                        <span class="kc-sub">{{ $m['veces'] ?? 1 }}× en 7d @if(!empty($m['contenedor']))· 🐳 {{ \Illuminate\Support\Str::limit($m['contenedor'], 16) }}@endif</span>
                                    @endif
                                </div>

                                {{-- Cuándo pasó (hora Colombia) --}}
                                @php($kwhen = $isMysql && !empty($m['last_seen']) ? $m['last_seen'] : optional($issue->last_seen_at)->format('d/m/Y H:i'))
                                <div class="kc-when" title="Hora de Colombia en que se registró esta incidencia por última vez">
                                    🕓 {{ $isMysql ? 'Últ. ejecución' : 'Últ. vez' }}: <b>{{ $kwhen ?? '—' }}</b> <span class="kc-flag">🇨🇴</span>
                                </div>

                                {{-- Distintivos (solo si aplican) --}}
                                @php($hasBadges = $issue->reopened_count > 0 || $issue->plan || ($isMysql && ($m['no_index_pct'] ?? 0) >= 30))
                                @if($hasBadges)
                                    <div class="kc-badges">
                                        @if($issue->reopened_count > 0)
                                            <span class="kbadge kbadge-bad" title="Ya se había resuelto y volvió a aparecer">🔁 ×{{ $issue->reopened_count }}</span>
                                        @endif
                                        @if($isMysql && ($m['no_index_pct'] ?? 0) >= 30)
                                            <span class="kbadge kbadge-bad" title="{{ $m['no_index_pct'] }}% de las filas se leen sin índice">{{ $m['no_index_pct'] }}% sin índice</span>
                                        @endif
                                        @if($issue->plan)
                                            @if($issue->plan->status === 'listo')
                                                <span class="kbadge kbadge-ok" title="Plan generado con IA{{ optional($issue->plan->generated_at) ? ' el '.$issue->plan->generated_at->format('d/m/Y H:i') : '' }}">✅ Plan</span>
                                            @elseif($issue->plan->status === 'error')
                                                <span class="kbadge kbadge-bad">⚠️ Plan</span>
                                            @else
                                                <span class="kbadge kbadge-ai">🧠 Plan…</span>
                                            @endif
                                            @if($issue->plan->github_pr_url)
                                                <a class="kbadge kbadge-pr" href="{{ $issue->plan->github_pr_url }}" target="_blank" rel="noopener">🚀 En GitHub</a>
                                            @endif
                                        @endif
                                    </div>
                                @endif

                                {{-- Acciones --}}
                                <div class="kacts">
                                    @if(in_array($issue->status, ['por_revisar','planeando'], true))
                                        <button class="kact-plan" data-plan="{{ $issue->id }}">{{ $issue->plan && $issue->plan->status === 'listo' ? '📋 Plan' : '🧠 Planear' }}</button>
                                    @endif
                                    @foreach($actions[$issue->status] ?? [] as [$st,$al])
                                        <button data-move="{{ $st }}">{{ $al }}</button>
                                    @endforeach
                                    <button class="kact-detail" data-detail="{{ $issue->id }}">ℹ️ Detalle</button>
                                </div>

                                {{-- Detalle completo (oculto; se mueve al modal al abrir) --}}
                                <div class="issue-detail" hidden
                                     data-title="{{ $isMysql ? '🗄️' : '🔥' }} {{ $issue->title }}"
                                     data-sev="{{ $se }} {{ $sn }}" data-sevcolor="{{ $sc }}">
                                    <p class="idt-summary">{{ $issue->summary }}</p>

                                    <section class="idt-sec">
                                        <h5>🩺 Causa probable</h5>
                                        <p class="idt-cause">{{ $issue->ai_cause }}</p>
                                    </section>

                                    <section class="idt-sec">
                                        <h5>🕓 Trazabilidad <span class="idt-flag">hora Colombia 🇨🇴</span></h5>
                                        <div class="idt-grid">
                                            <div><span>Detectada</span><b>{{ optional($issue->first_detected_at)->format('d/m/Y H:i') ?? '—' }}</b></div>
                                            <div><span>Vista por última vez</span><b>{{ optional($issue->last_seen_at)->format('d/m/Y H:i') ?? '—' }}</b></div>
                                            @if($isMysql && !empty($m['last_seen']))
                                                <div><span>Última ejecución en MySQL</span><b>{{ $m['last_seen'] }}</b></div>
                                            @endif
                                            <div><span>Reincidencias</span><b>{{ $issue->reopened_count > 0 ? '🔁 '.$issue->reopened_count.' vez(es)' : 'Ninguna' }}</b></div>
                                        </div>
                                    </section>

                                    <section class="idt-sec">
                                        <h5>📊 Métricas</h5>
                                        <div class="idt-chips">
                                            @if($isMysql)
                                                <span class="kchip"><span>Base de datos</span>{{ $m['db'] ?? '—' }}</span>
                                                <span class="kchip"><span>Tiempo total</span>{{ number_format($m['total_s'] ?? 0) }} s</span>
                                                <span class="kchip"><span>Ejecuciones</span>{{ number_format($m['execs'] ?? 0) }}</span>
                                                <span class="kchip"><span>Promedio</span>{{ ($m['avg_ms'] ?? 0) >= 1000 ? round(($m['avg_ms'])/1000,1).' s' : ($m['avg_ms'] ?? 0).' ms' }}</span>
                                                @if(($m['no_index_pct'] ?? 0) > 0)<span class="kchip"><span>Sin índice</span>{{ $m['no_index_pct'] }}%</span>@endif
                                            @else
                                                <span class="kchip"><span>CPU máxima</span>{{ $m['max_cpu'] ?? '?' }}%</span>
                                                <span class="kchip"><span>Carga máx (1m)</span>{{ $m['max_load'] ?? '?' }}</span>
                                                <span class="kchip"><span>Veces (7d)</span>{{ $m['veces'] ?? 1 }}</span>
                                                @if(!empty($m['contenedor']))<span class="kchip"><span>Contenedor</span>🐳 {{ $m['contenedor'] }}</span>@endif
                                                @if(($m['hora_pico'] ?? null) !== null)<span class="kchip"><span>Hora pico</span>~{{ $m['hora_pico'] }}:00</span>@endif
                                                @if(!empty($m['proceso']))<span class="kchip"><span>Proceso</span>{{ \Illuminate\Support\Str::limit($m['proceso'], 40) }}</span>@endif
                                            @endif
                                        </div>
                                    </section>

                                    @if($isMysql && !empty($m['query']))
                                        <section class="idt-sec">
                                            <h5>🧾 Consulta</h5>
                                            <pre class="idt-sql">{{ $m['query'] }}</pre>
                                        </section>
                                    @endif

                                    @if($issue->events->isNotEmpty())
                                        <section class="idt-sec">
                                            <h5>🗓️ Historial ({{ $issue->events->count() }})</h5>
                                            <ul class="idt-timeline">
                                                @foreach($issue->events as $ev)
                                                    <li><span class="idt-ev-ic">{{ $ev->icon() }}</span>
                                                        <span class="idt-ev-note">{{ $ev->note }}</span>
                                                        <span class="idt-ev-when">{{ $ev->happened_at->format('d/m/Y H:i') }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </section>
                                    @endif

                                    <section class="idt-sec">
                                        <h5>🤖 Contexto para la IA</h5>
                                        <p class="tiny muted" style="margin:0 0 6px">Cópialo para pedir el plan de solución en cualquier asistente.</p>
                                        <div class="idt-ia-acts">
                                            <button class="btn btn-sm" data-copy="issue-ai-{{ $issue->id }}">📋 Copiar contexto</button>
                                            @if($issue->link)<a class="btn btn-ghost btn-sm" href="{{ $issue->link }}">🔎 Ver en optimizador</a>@endif
                                        </div>
                                        <textarea id="issue-ai-{{ $issue->id }}" readonly class="idt-ia-text">{{ $issue->ai_prompt }}</textarea>
                                    </section>
                                </div>
                            </div>
                        @empty
                            <div class="kempty">—</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        <p class="muted tiny" style="margin-top:14px">💡 Las tarjetas muestran solo lo esencial; toca <b>ℹ️ Detalle</b> para ver la trazabilidad, la consulta y el historial. Las que dejen de aparecer 30 min pasan solas a <b>✅ Resueltas</b>; si mueves una a mano, el panel respeta tu decisión (los procesos de Docker que no se pueden optimizar → <b>🔒 No aplica</b>). En las consultas MySQL, <b>🧠 Planear</b> investiga el código en GitHub y genera el plan con IA en vivo.</p>
    @endif
@endif
