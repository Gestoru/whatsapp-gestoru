{{-- Columnas y tarjetas del tablero de rendimiento (se carga por AJAX) --}}
@if($error)
    <div class="alert alert-bad" style="margin-top:14px">{{ $error }}</div>
@else
    @php
        $sevMeta = ['critica' => ['#ff4d6d','🔴 Crítica'], 'alta' => ['#fbbf24','🟡 Alta'], 'media' => ['#38bdf8','🔵 Media']];
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
        <div class="row" style="gap:14px;margin:6px 0 12px;flex-wrap:wrap">
            @foreach($board as $col => $issues)
                @php([$ic,$lbl,$cc] = $columns[$col])
                <span class="tiny" style="color:{{ $cc }};font-weight:600">{{ $ic }} {{ $lbl }}: {{ count($issues) }}</span>
            @endforeach
        </div>

        <div class="kanban">
            @foreach($board as $col => $issues)
                @php([$ic,$lbl,$cc] = $columns[$col])
                <div class="kcol" data-col="{{ $col }}">
                    <div class="kcol-head" style="color:{{ $cc }}">{{ $ic }} {{ $lbl }} <span class="kcount">{{ count($issues) }}</span></div>
                    <div class="kcol-body">
                        @forelse($issues as $issue)
                            @php([$sc,$sl] = $sevMeta[$issue->severity] ?? $sevMeta['media'])
                            @php($m = $issue->metrics ?? [])
                            <div class="kcard" data-id="{{ $issue->id }}" data-status="{{ $issue->status }}" data-kind="{{ $issue->kind }}" style="border-left-color:{{ $sc }}">
                                <h4>
                                    <span>{{ $issue->kind === 'mysql_query' ? '🗄️' : '🔥' }}</span>
                                    <span style="flex:1;min-width:0;word-break:break-word">{{ $issue->title }}</span>
                                    <span class="tag" style="background:{{ $sc }}22;color:{{ $sc }};font-size:10px">{{ $sl }}</span>
                                </h4>
                                <div class="tiny muted">{{ $issue->summary }}</div>

                                @if($issue->reopened_count > 0)
                                    <div style="margin-top:6px"><span class="tag" style="background:#ff4d6d22;color:#ff4d6d;font-size:10.5px" title="Ya se había resuelto y volvió a aparecer">🔁 Reincidente ×{{ $issue->reopened_count }} · ya se había resuelto y volvió</span></div>
                                @endif

                                @if($issue->plan)
                                    <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap">
                                        @if($issue->plan->status === 'listo')
                                            <span class="tag" style="background:#22e39b22;color:#22e39b;font-size:10.5px" title="Plan generado con IA el {{ optional($issue->plan->generated_at)->format('d/m/Y H:i') }}">✅ Plan de IA listo</span>
                                        @elseif($issue->plan->status === 'error')
                                            <span class="tag" style="background:#ff4d6d22;color:#ff4d6d;font-size:10.5px">⚠️ Plan con error</span>
                                        @else
                                            <span class="tag" style="background:#c084fc22;color:#c084fc;font-size:10.5px">🧠 Plan en curso…</span>
                                        @endif
                                        @if($issue->plan->github_pr_url)
                                            <a class="tag" href="{{ $issue->plan->github_pr_url }}" target="_blank" rel="noopener" style="background:#6366f122;color:#a5b4fc;font-size:10.5px;text-decoration:none">🚀 PR en GitHub</a>
                                        @endif
                                    </div>
                                @endif

                                <div class="tiny muted" style="margin-top:6px;line-height:1.6">
                                    🕓 Detectada: <b>{{ optional($issue->first_detected_at)->format('d/m/Y H:i') ?? '—' }}</b><br>
                                    Vista por última vez: <b>{{ optional($issue->last_seen_at)->format('d/m/Y H:i') ?? '—' }}</b>
                                    @if($issue->kind === 'mysql_query' && !empty($m['last_seen']))<br>Última ejecución en MySQL: <b>{{ $m['last_seen'] }}</b>@endif
                                    <span style="opacity:.6">🇨🇴</span>
                                </div>

                                <div class="kmetrics">
                                    @if($issue->kind === 'cpu_peak')
                                        <span class="kchip">máx {{ $m['max_cpu'] ?? '?' }}% CPU</span>
                                        <span class="kchip">{{ $m['veces'] ?? 1 }}× en 7d</span>
                                        @if(!empty($m['contenedor']))<span class="kchip">🐳 {{ $m['contenedor'] }}</span>@endif
                                        @if(($m['hora_pico'] ?? null) !== null)<span class="kchip">~{{ $m['hora_pico'] }}:00 🇨🇴</span>@endif
                                    @else
                                        <span class="kchip">{{ number_format($m['total_s'] ?? 0) }}s tot.</span>
                                        <span class="kchip">{{ number_format($m['execs'] ?? 0) }}×</span>
                                        <span class="kchip">{{ ($m['avg_ms'] ?? 0) >= 1000 ? round(($m['avg_ms'])/1000,1).'s' : ($m['avg_ms'] ?? 0).'ms' }} prom</span>
                                        @if(($m['no_index_pct'] ?? 0) >= 30)<span class="kchip" style="color:#fca5a5">{{ $m['no_index_pct'] }}% sin índice</span>@endif
                                    @endif
                                </div>

                                @if($issue->kind === 'mysql_query' && !empty($m['query']))
                                    <div class="fb-file" style="border:1px solid var(--line);border-radius:8px;margin:6px 0"><pre style="max-height:70px;font-size:11px">{{ \Illuminate\Support\Str::limit($m['query'], 140) }}</pre></div>
                                @endif

                                <div class="cause">🩺 <b>Causa probable:</b> {{ $issue->ai_cause }}</div>

                                @if($issue->events->isNotEmpty())
                                    <details style="margin-top:6px">
                                        <summary style="cursor:pointer;font-size:11.5px;color:#7dd3fc;list-style:none">🕓 Historial ({{ $issue->events->count() }})</summary>
                                        <div style="margin-top:6px;border-left:2px solid var(--line);padding-left:9px">
                                            @foreach($issue->events as $ev)
                                                <div class="tiny muted" style="margin:3px 0;line-height:1.5">
                                                    {{ $ev->icon() }} {{ $ev->note }}
                                                    <span style="opacity:.6">· {{ $ev->happened_at->format('d/m/Y H:i') }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                <div class="kacts">
                                    @if($issue->kind === 'mysql_query' && in_array($issue->status, ['por_revisar','planeando'], true))
                                        <button data-plan="{{ $issue->id }}" style="border-color:#c084fc66;color:#c084fc">
                                            {{ $issue->plan && $issue->plan->status === 'listo' ? '📋 Ver plan' : '🧠 Planear' }}
                                        </button>
                                    @endif
                                    @foreach($actions[$issue->status] ?? [] as [$st,$al])
                                        <button data-move="{{ $st }}">{{ $al }}</button>
                                    @endforeach
                                    @if($issue->link)<a href="{{ $issue->link }}">🔎 Detalle</a>@endif
                                    <button data-copy="issue-ai-{{ $issue->id }}" style="border-color:#6366f166;color:#a5b4fc">🤖 IA</button>
                                </div>
                                <textarea id="issue-ai-{{ $issue->id }}" readonly style="display:none">{{ $issue->ai_prompt }}</textarea>
                            </div>
                        @empty
                            <div class="kempty">—</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        <p class="muted tiny" style="margin-top:14px">💡 Las tarjetas se detectan solas. Las que dejen de aparecer 30 min pasan a <b>✅ Resueltas</b> automáticamente. Si mueves una a mano, el panel respeta tu decisión (útil para los procesos de Docker que no se pueden optimizar → <b>🔒 No aplica</b>). En las consultas MySQL, <b>🧠 Planear</b> conecta con GitHub, investiga el código y genera el plan de optimización con IA en vivo; el botón <b>🤖 IA</b> copia el contexto para usarlo por fuera.</p>
    @endif
@endif
