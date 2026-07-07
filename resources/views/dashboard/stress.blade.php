@extends('dashboard.layout')
@section('title', 'Prueba de estrés · '.$server->name)
@section('subtitle', $server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.show', $server) }}" class="btn btn-ghost btn-sm">← {{ $server->name }}</a>
    <a href="{{ route('dashboard.servers.trends', $server) }}" class="btn btn-sm">📉 Ver tendencias en vivo</a>
@endsection

@section('content')
    <h1 style="margin-bottom:4px">🧪 Prueba de estrés — {{ $server->name }}</h1>
    <p class="muted tiny">Genera carga real contra tus propios sitios para descubrir errores y límites <strong>antes</strong> de que ocurran en producción.</p>

    <div class="alert" style="background:#2e2410;border-color:#6b5316;color:#fcd34d;margin-top:12px">
        ⚠️ <strong>Esto genera tráfico real</strong> al sitio. Hazlo en horas de bajo movimiento y empieza suave.
        Por seguridad, solo puedes probar dominios de <strong>este</strong> servidor.
    </div>

    @if($error)
        <div class="alert alert-bad">{{ $error }}</div>
    @endif

    @if(!$server->hasCredentials())
        <a href="{{ route('dashboard.servers.edit', $server) }}" class="btn btn-primary">🔑 Poner contraseña primero</a>
    @else
        {{-- Formulario --}}
        <form method="POST" action="{{ route('dashboard.servers.stress.run', $server) }}"
              onsubmit="return confirm('Vas a lanzar tráfico real contra el sitio. ¿Continuar?')">
            @csrf
            <div class="card">
                <div class="field">
                    <label>URL a probar <span class="muted">(un sitio de este servidor)</span></label>
                    <input name="url" value="{{ old('url', $result['url'] ?? ($targets[0] ?? '' ? 'https://'.($targets[0] ?? '') : '')) }}"
                           placeholder="https://tudominio.com" required list="targets">
                    <datalist id="targets">
                        @foreach($targets as $t)
                            <option value="https://{{ $t }}"></option>
                        @endforeach
                    </datalist>
                    @if($targets)
                        <div class="tiny muted" style="margin-top:5px">Permitidos: {{ implode(' · ', $targets) }}</div>
                    @endif
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label>Duración (segundos) · máx {{ \App\Services\StressTester::MAX_SECONDS }}</label>
                        <input name="seconds" type="number" min="3" max="{{ \App\Services\StressTester::MAX_SECONDS }}" value="{{ old('seconds', $result['seconds'] ?? 10) }}" required>
                    </div>
                    <div class="field">
                        <label>Usuarios simultáneos · máx {{ \App\Services\StressTester::MAX_CONCURRENCY }}</label>
                        <input name="concurrency" type="number" min="1" max="{{ \App\Services\StressTester::MAX_CONCURRENCY }}" value="{{ old('concurrency', $result['concurrency'] ?? 10) }}" required>
                    </div>
                </div>
                <div class="row" style="justify-content:space-between;align-items:center">
                    <span class="tiny muted">💡 Consejo: empieza con 10s y 10 usuarios; sube de a poco viendo el resultado.</span>
                    <button class="btn btn-primary" id="run-btn">🚀 Lanzar prueba</button>
                </div>
            </div>
        </form>

        {{-- Resultado --}}
        @if($result)
            @php($v = $result['verdict'])
            @php($tone = ['ok'=>['#0f2a1a','#1e5637','#86efac'],'warn'=>['#2e2410','#6b5316','#fcd34d'],'bad'=>['#2a1116','#5b2330','#fca5a5']][$v['level']])
            <h2><span class="section-ic">📋</span> Resultado</h2>
            <div class="card" style="background:{{ $tone[0] }};border-color:{{ $tone[1] }};margin-bottom:14px">
                <div style="font-size:16px;font-weight:700;color:{{ $tone[2] }}">
                    {{ ['ok'=>'✅','warn'=>'⚠️','bad'=>'🔴'][$v['level']] }} {{ $v['text'] }}
                </div>
            </div>

            <div class="stat-grid" style="margin-bottom:14px">
                <div class="stat"><div class="k">Peticiones totales</div><div class="v">{{ number_format($result['total']) }}</div></div>
                <div class="stat"><div class="k">Peticiones/segundo</div><div class="v">{{ number_format($result['rps']) }}</div></div>
                <div class="stat"><div class="k">Respuesta promedio</div><div class="v">{{ number_format($result['avg_ms']) }} ms</div></div>
                <div class="stat"><div class="k">Respuesta máxima</div><div class="v">{{ number_format($result['max_ms']) }} ms</div></div>
                <div class="stat"><div class="k">Errores</div><div class="v" style="color:{{ $result['error_pct'] > 0 ? 'var(--bad)' : 'var(--ok)' }}">{{ $result['error_pct'] }}%</div></div>
                <div class="stat"><div class="k">CPU durante</div><div class="v">{{ $result['cpu_during'] !== null ? $result['cpu_during'].'%' : '—' }}</div></div>
            </div>

            <div class="list-card">
                <div class="fb-head">Códigos de respuesta obtenidos</div>
                <table>
                    <tbody>
                    @foreach($result['codes'] as $code => $count)
                        @php($t = $code === '000' ? 'var(--bad)' : ((int)$code >= 500 ? 'var(--bad)' : ((int)$code >= 400 ? 'var(--warn)' : 'var(--ok)')))
                        <tr>
                            <td><span style="color:{{ $t }};font-weight:700">{{ $code === '000' ? 'sin respuesta / timeout' : $code }}</span></td>
                            <td class="muted tiny">{{ $code==='000'?'conexión fallida':((int)$code>=500?'error del servidor':((int)$code>=400?'error del cliente':'correcto')) }}</td>
                            <td style="text-align:right;font-weight:600">{{ number_format($count) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <p class="muted tiny" style="margin-top:12px">💡 Mientras corre una prueba, abre <a href="{{ route('dashboard.servers.trends', $server) }}" style="color:var(--accent)">Tendencias en vivo</a> en otra pestaña para ver el CPU y la RAM reaccionar en tiempo real.</p>
        @endif
    @endif
@endsection

@push('scripts')
<script>
document.querySelector('form')?.addEventListener('submit', e => {
    const b = document.getElementById('run-btn');
    if (b) { b.disabled = true; b.innerHTML = '<span class="spin"></span> Probando… (espera el resultado)'; }
});
</script>
@endpush
