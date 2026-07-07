@extends('dashboard.layout')
@section('title', 'Alertas · '.config('dashboard.title'))
@section('subtitle', 'avisos automáticos por WhatsApp')

@section('content')
<div style="max-width:680px;margin:0 auto">
    <h1 style="margin-bottom:4px">🔔 Alertas por WhatsApp</h1>
    <p class="muted tiny">El panel te avisa por WhatsApp cuando un servidor tenga un problema, para que estés un paso adelante.</p>

    @if($errors->any())<div class="alert alert-bad">{{ $errors->first() }}</div>@endif

    @unless($waConfigured)
        <div class="alert" style="background:#2e2410;border-color:#6b5316;color:#fcd34d">
            ⚠️ Falta conectar el servidor de WhatsApp (<code>VPS_API_URL</code>). Puedes configurar las alertas igual, pero no se enviarán hasta que el WhatsApp esté conectado.
        </div>
    @endunless

    <form method="POST" action="{{ route('dashboard.alerts.update') }}">
        @csrf @method('PUT')
        <div class="card">
            <div class="field" style="display:flex;align-items:center;gap:10px">
                <input type="checkbox" name="alerts_enabled" value="1" id="en" style="width:auto" @checked($enabled)>
                <label for="en" style="margin:0">Activar alertas por WhatsApp</label>
            </div>

            <div class="field">
                <label>Número de WhatsApp <span class="muted">(con código de país, ej. 57 para Colombia)</span></label>
                <input name="alerts_phone" value="{{ old('alerts_phone', $phone) }}" placeholder="573001234567" inputmode="numeric">
                <div class="tiny muted" style="margin-top:5px">Escríbelo solo con números: código de país + número. Ej: <strong>573001234567</strong></div>
            </div>

            <h2 style="font-size:14px;margin:8px 0 12px;color:var(--muted)">¿Cuándo avisar?</h2>
            <div class="form-grid">
                <div class="field">
                    <label>CPU por encima de (%)</label>
                    <input name="alert_cpu" type="number" min="1" max="100" value="{{ old('alert_cpu', $cpu) }}" required>
                </div>
                <div class="field">
                    <label>Disco por encima de (%)</label>
                    <input name="alert_disk" type="number" min="1" max="100" value="{{ old('alert_disk', $disk) }}" required>
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label>Memoria RAM por encima de (%)</label>
                    <input name="alert_mem" type="number" min="1" max="100" value="{{ old('alert_mem', $mem) }}" required>
                </div>
                <div class="field">
                    <label>Silencio entre avisos (minutos)</label>
                    <input name="alert_cooldown" type="number" min="5" max="1440" value="{{ old('alert_cooldown', $cooldown) }}" required>
                    <div class="tiny muted" style="margin-top:5px">Evita spam: no repite el mismo aviso hasta pasado este tiempo.</div>
                </div>
            </div>

            <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                📩 También te avisa si un servidor <strong style="color:var(--text)">deja de responder</strong> (se cae). Las alertas se revisan cada 5 minutos junto con el muestreo.
            </div>

            <div class="row" style="justify-content:flex-end">
                <button class="btn btn-primary">Guardar</button>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('dashboard.alerts.test') }}" style="margin-top:14px">
        @csrf
        <button class="btn" @disabled(!$waConfigured)>📤 Enviar mensaje de prueba</button>
        <span class="tiny muted" style="margin-left:8px">Envía un WhatsApp de prueba al número configurado.</span>
    </form>
</div>
@endsection
