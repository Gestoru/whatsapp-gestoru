@extends('dashboard.layout')
@section('title', 'Configuración')
@section('subtitle', 'todos los ajustes del sistema en un solo lugar')

@section('content')
<style>
    .cfg{display:grid;grid-template-columns:220px minmax(0,1fr);gap:22px;align-items:start}
    .cfg-tabs{display:flex;flex-direction:column;gap:4px;position:sticky;top:78px}
    .cfg-tab{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:12px;cursor:pointer;
        color:var(--muted);font-weight:600;font-size:14.5px;border:1px solid transparent;transition:.14s}
    .cfg-tab .ic{font-size:17px;width:22px;text-align:center}
    .cfg-tab:hover{background:var(--card2);color:var(--text)}
    .cfg-tab.on{color:#fff;background:linear-gradient(90deg,rgba(109,108,247,.20),transparent);
        border-color:var(--line2)}
    .cfg-tab .badge{margin-left:auto;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:999px}
    .badge-on{background:#0f2a1a;color:#6ee7a8;border:1px solid #1e5637}
    .badge-off{background:#2a1a10;color:#fbbf6b;border:1px solid #6b4a16}
    .cfg-panel{display:none;animation:fade .2s ease}
    .cfg-panel.on{display:block}
    @keyframes fade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .cfg-hd{display:flex;align-items:center;gap:12px;margin-bottom:4px}
    .cfg-hd .em{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;font-size:22px;
        background:linear-gradient(145deg,var(--card2),#141d38);border:1px solid var(--line)}
    .cfg-hd h1{font-size:20px}
    .cfg-hd p{margin:2px 0 0;font-size:13px}
    .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:6px}
    .info-tile{background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:14px}
    .info-tile .k{font-size:12px;color:var(--muted)}
    .info-tile .v{font-size:16px;font-weight:700;margin-top:4px;word-break:break-word}
    @media(max-width:760px){
        .cfg{grid-template-columns:1fr}
        .cfg-tabs{flex-direction:row;overflow-x:auto;position:static;padding-bottom:4px}
        .cfg-tab{white-space:nowrap}.cfg-tab .badge{display:none}
    }
</style>

@if($errors->any())<div class="alert alert-bad">{{ $errors->first() }}</div>@endif

<div class="cfg">
    {{-- Navegación lateral de configuración --}}
    <div class="cfg-tabs">
        <div class="cfg-tab on" data-tab="general"><span class="ic">🎛️</span> General</div>
        <div class="cfg-tab" data-tab="alertas"><span class="ic">🔔</span> Alertas
            <span class="badge {{ $enabled ? 'badge-on':'badge-off' }}">{{ $enabled ? 'ON':'OFF' }}</span></div>
        <div class="cfg-tab" data-tab="godaddy"><span class="ic">🌐</span> GoDaddy
            <span class="badge {{ $godaddyConfigured ? 'badge-on':'badge-off' }}">{{ $godaddyConfigured ? '✔':'—' }}</span></div>
        <div class="cfg-tab" data-tab="contabo"><span class="ic">🟠</span> Contabo
            <span class="badge {{ $contaboConfigured ? 'badge-on':'badge-off' }}">{{ $contaboConfigured ? '✔':'—' }}</span></div>
        <div class="cfg-tab" data-tab="github"><span class="ic">📚</span> GitHub
            <span class="badge {{ $githubConfigured ? 'badge-on':'badge-off' }}">{{ $githubConfigured ? '✔':'—' }}</span></div>
    </div>

    <div>
        {{-- ══ GENERAL ══ --}}
        <section class="cfg-panel on" id="p-general">
            <div class="cfg-hd">
                <div class="em">🎛️</div>
                <div><h1>General</h1><p class="muted">Datos del sistema y preferencias globales.</p></div>
            </div>
            <div class="card" style="margin-top:16px">
                <div class="info-grid">
                    <div class="info-tile"><div class="k">Nombre corto</div><div class="v">{{ $general['brand'] }}</div></div>
                    <div class="info-tile"><div class="k">Panel</div><div class="v">{{ $general['title'] }}</div></div>
                    <div class="info-tile"><div class="k">Zona horaria</div><div class="v">{{ $general['timezone'] }}</div></div>
                    <div class="info-tile"><div class="k">Umbral de pico CPU</div><div class="v">{{ $general['cpuPeak'] }}%</div></div>
                    <div class="info-tile"><div class="k">Acceso protegido</div>
                        <div class="v">{{ $general['hasPassword'] ? '🔒 Con contraseña' : '⚠️ Sin contraseña' }}</div></div>
                </div>
                <p class="muted tiny" style="margin:14px 0 0">
                    Estos valores se definen en el archivo <code>.env</code> del servidor
                    (<code>DASHBOARD_BRAND</code>, <code>DASHBOARD_TITLE</code>, <code>APP_TIMEZONE</code>,
                    <code>DASHBOARD_CPU_PEAK</code>, <code>DASHBOARD_PASSWORD</code>). Cámbialos ahí y recarga.
                </p>
            </div>
        </section>

        {{-- ══ ALERTAS ══ --}}
        <section class="cfg-panel" id="p-alertas">
            <div class="cfg-hd">
                <div class="em">🔔</div>
                <div><h1>Alertas por WhatsApp</h1><p class="muted">Avisos automáticos cuando un servidor tenga problemas.</p></div>
            </div>

            {{-- Servidor de WhatsApp: conexión + estado + QR --}}
            <div class="card" style="margin-top:16px">
                <div class="row" style="justify-content:space-between;align-items:center">
                    <h2 style="margin:0;font-size:15px">📲 Servidor de WhatsApp</h2>
                    <span id="wa-badge" class="pill"><span class="dot" id="wa-dot"></span> <span id="wa-badge-txt">comprobando…</span></span>
                </div>
                <p class="muted tiny" style="margin:8px 0 12px">
                    Para enviar los avisos, el panel usa un servidor de WhatsApp (incluido en <code>vps-server/</code>).
                    Indica su dirección y vincula tu WhatsApp escaneando el QR.
                </p>

                <form method="POST" action="{{ route('dashboard.alerts.wa.save') }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field"><label>Dirección del servidor (URL)</label>
                            <input name="wa_api_url" value="{{ $waApiUrl }}" placeholder="http://127.0.0.1:3000"></div>
                        <div class="field"><label>Clave (opcional) @if($waConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                            <input name="wa_api_key" type="password" autocomplete="new-password" placeholder="••••••••"></div>
                    </div>
                    <div class="row" style="justify-content:flex-end"><button class="btn btn-primary btn-sm">Guardar servidor</button></div>
                </form>

                @if($waConfigured)
                    <div style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
                        <div class="row" style="gap:10px">
                            <button id="wa-qr-btn" class="btn btn-sm" type="button">📱 Vincular WhatsApp (mostrar QR)</button>
                            <form method="POST" action="{{ route('dashboard.alerts.wa.disconnect') }}" style="display:inline"
                                  onsubmit="return confirm('¿Desconectar la sesión de WhatsApp?')">
                                @csrf
                                <button class="btn btn-sm btn-danger">Desconectar</button>
                            </form>
                        </div>
                        <div id="wa-qr-box" class="hidden" style="margin-top:14px;text-align:center">
                            <p class="muted tiny">En tu teléfono: WhatsApp → <strong>Dispositivos vinculados</strong> → <strong>Vincular un dispositivo</strong>, y escanea:</p>
                            <img id="wa-qr-img" alt="QR de WhatsApp" style="width:240px;height:240px;background:#fff;border-radius:12px;padding:8px;object-fit:contain">
                            <p class="muted tiny" id="wa-qr-hint">Generando el código…</p>
                        </div>
                    </div>
                @else
                    <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted);margin-top:12px">
                        Guarda la dirección del servidor (y déjalo corriendo) para poder vincular WhatsApp y enviar avisos.
                        Si corre en el mismo servidor del panel, suele ser <code>http://127.0.0.1:3000</code>.
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('dashboard.alerts.update') }}" style="margin-top:16px">
                @csrf @method('PUT')
                <div class="card">
                    <div class="field" style="display:flex;align-items:center;gap:10px">
                        <input type="checkbox" name="alerts_enabled" value="1" id="en" style="width:auto" @checked($enabled)>
                        <label for="en" style="margin:0">Activar alertas por WhatsApp</label>
                    </div>
                    <div class="field">
                        <label>Números que recibirán la alerta <span class="muted">(con código de país; uno por línea o separados por coma)</span></label>
                        <textarea name="alerts_phones" rows="3" placeholder="573001234567&#10;573109876543">{{ old('alerts_phones', $phone) }}</textarea>
                        <div class="tiny muted" style="margin-top:5px">
                            El WhatsApp <strong>vinculado</strong> (arriba) es quien <strong>envía</strong>; estos números son quienes <strong>reciben</strong> la alerta. Deben ser distintos del número vinculado.
                        </div>
                    </div>
                    <h2 style="font-size:14px;margin:8px 0 12px;color:var(--muted)">¿Cuándo avisar?</h2>
                    <div class="form-grid">
                        <div class="field"><label>CPU por encima de (%)</label>
                            <input name="alert_cpu" type="number" min="1" max="100" value="{{ old('alert_cpu', $cpu) }}" required></div>
                        <div class="field"><label>Disco por encima de (%)</label>
                            <input name="alert_disk" type="number" min="1" max="100" value="{{ old('alert_disk', $disk) }}" required></div>
                    </div>
                    <div class="form-grid">
                        <div class="field"><label>Memoria RAM por encima de (%)</label>
                            <input name="alert_mem" type="number" min="1" max="100" value="{{ old('alert_mem', $mem) }}" required></div>
                        <div class="field"><label>Silencio entre avisos (minutos)</label>
                            <input name="alert_cooldown" type="number" min="5" max="1440" value="{{ old('alert_cooldown', $cooldown) }}" required></div>
                    </div>
                    <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                        📩 También te avisa si un servidor <strong style="color:var(--text)">deja de responder</strong>. Se revisa cada 5 minutos.
                    </div>
                    <div class="row" style="justify-content:flex-end;gap:8px">
                        {{-- «Enviar prueba» va en su propio formulario (POST), aparte del PUT de guardar --}}
                        <button form="wa-test-form" class="btn" @disabled(!$waConfigured)>📤 Enviar prueba</button>
                        <button class="btn btn-primary">Guardar alertas</button>
                    </div>
                </div>
            </form>
            <form id="wa-test-form" method="POST" action="{{ route('dashboard.alerts.test') }}">@csrf</form>
        </section>

        {{-- ══ GODADDY ══ --}}
        <section class="cfg-panel" id="p-godaddy">
            <div class="cfg-hd">
                <div class="em">🌐</div>
                <div><h1>Integración GoDaddy</h1><p class="muted">Estado, vencimiento y auto-renovación de tus dominios.</p></div>
            </div>
            <div class="card" style="margin-top:16px">
                <p class="muted tiny" style="margin:0 0 12px">
                    @if($godaddyConfigured)
                        <span style="color:var(--ok)">✔ Conectado.</span>
                        @if($godaddyLastSync) Última sincronización: {{ \Carbon\Carbon::parse($godaddyLastSync)->diffForHumans() }}.@endif
                    @else Aún no conectado. @endif
                </p>
                <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                    <strong style="color:var(--text)">Cómo obtener tus llaves:</strong>
                    <ol style="margin:8px 0 0 18px;padding:0">
                        <li>Entra a <span style="font-family:ui-monospace,monospace">developer.godaddy.com/keys</span>.</li>
                        <li>Crea una API Key de <strong style="color:var(--text)">Producción</strong>.</li>
                        <li>Copia la <strong style="color:var(--text)">Key</strong> y el <strong style="color:var(--text)">Secret</strong>.</li>
                    </ol>
                </div>
                <form method="POST" action="{{ route('dashboard.domains.godaddy.connect') }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field"><label>API Key</label><input name="godaddy_api_key" value="{{ $godaddyKey }}" placeholder="dLD..." required></div>
                        <div class="field"><label>API Secret @if($godaddyConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                            <input name="godaddy_api_secret" type="password" autocomplete="new-password" placeholder="••••••••"></div>
                    </div>
                    <div class="row" style="justify-content:flex-end;gap:8px">
                        <button class="btn btn-primary btn-sm">Guardar credenciales</button>
                        @if($godaddyConfigured)
                            <button formaction="{{ route('dashboard.domains.godaddy.sync') }}" class="btn btn-sm">🔄 Sincronizar ahora</button>
                        @endif
                    </div>
                </form>
                <p class="muted tiny" style="margin-top:8px">🔒 El secreto se guarda cifrado.</p>
            </div>
        </section>

        {{-- ══ CONTABO ══ --}}
        <section class="cfg-panel" id="p-contabo">
            <div class="cfg-hd">
                <div class="em">🟠</div>
                <div><h1>Integración Contabo</h1><p class="muted">Estado, plan, región y próxima renovación de tus VPS.</p></div>
            </div>
            <div class="card" style="margin-top:16px">
                <p class="muted tiny" style="margin:0 0 12px">
                    @if($contaboConfigured)<span style="color:var(--ok)">✔ Conectado.</span>@else Aún no conectado.@endif
                </p>
                <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                    <strong style="color:var(--text)">Cómo obtener tus credenciales:</strong>
                    <ol style="margin:8px 0 0 18px;padding:0">
                        <li>Entra a <span style="font-family:ui-monospace,monospace">my.contabo.com</span> → <strong style="color:var(--text)">API / Secrets</strong>.</li>
                        <li>Copia el <strong style="color:var(--text)">Client Id</strong> y <strong style="color:var(--text)">Client Secret</strong>.</li>
                        <li>Tu <strong style="color:var(--text)">usuario API</strong> es tu email; ahí defines la <strong style="color:var(--text)">API Password</strong>.</li>
                    </ol>
                </div>
                <form method="POST" action="{{ route('dashboard.contabo.connect') }}">
                    @csrf
                    <div class="form-grid">
                        <div class="field"><label>Client Id</label><input name="contabo_client_id" value="{{ $contaboClientId }}" required></div>
                        <div class="field"><label>Client Secret @if($contaboConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                            <input name="contabo_client_secret" type="password" autocomplete="new-password" placeholder="••••••••"></div>
                    </div>
                    <div class="form-grid">
                        <div class="field"><label>Usuario API (email de Contabo)</label><input name="contabo_api_user" value="{{ $contaboApiUser }}" required></div>
                        <div class="field"><label>API Password @if($contaboConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                            <input name="contabo_api_password" type="password" autocomplete="new-password" placeholder="••••••••"></div>
                    </div>
                    <div class="row" style="justify-content:flex-end;gap:8px">
                        <button class="btn btn-primary btn-sm">Guardar credenciales</button>
                        @if($contaboConfigured)
                            <button formaction="{{ route('dashboard.contabo.sync') }}" class="btn btn-sm">🔄 Sincronizar ahora</button>
                        @endif
                    </div>
                </form>
                <p class="muted tiny" style="margin-top:8px">🔒 Los secretos se guardan cifrados.</p>
            </div>
        </section>

        {{-- ══ GITHUB ══ --}}
        <section class="cfg-panel" id="p-github">
            <div class="cfg-hd">
                <div class="em">📚</div>
                <div><h1>Integración GitHub</h1><p class="muted">Sincroniza los repositorios de tus organizaciones y tu cuenta.</p></div>
            </div>
            <div class="card" style="margin-top:16px">
                <p class="muted tiny" style="margin:0 0 12px">
                    @if($githubConfigured)
                        <span style="color:var(--ok)">✔ Conectado.</span>
                        {{ $githubRepoCount }} repositorios en el panel.
                        @if($githubLastSync) Última sincronización: {{ \Carbon\Carbon::parse($githubLastSync)->diffForHumans() }}.@endif
                    @else Aún no conectado. @endif
                </p>
                <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                    <strong style="color:var(--text)">Cómo obtener tu token (1 minuto, tú eliges organización y permisos):</strong>
                    <ol style="margin:8px 0 0 18px;padding:0">
                        <li>Entra a <span style="font-family:ui-monospace,monospace">github.com/settings/personal-access-tokens/new</span> (token «Fine-grained»).</li>
                        <li>En <strong style="color:var(--text)">Resource owner</strong> elige tu <strong style="color:var(--text)">organización</strong> (o tu usuario).</li>
                        <li>En permisos, da <strong style="color:var(--text)">Repository → Contents/Metadata: Read-only</strong> (solo lectura).</li>
                        <li>Genera el token, cópialo y pégalo aquí abajo.</li>
                    </ol>
                    <div class="tiny" style="margin-top:8px">También sirve un token clásico con el scope <code>repo</code> (o <code>public_repo</code> para solo públicos).</div>
                </div>
                <form method="POST" action="{{ route('dashboard.repositories.github.connect') }}">
                    @csrf
                    <div class="field">
                        <label>Token de GitHub @if($githubConfigured)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                        <input name="github_token" type="password" autocomplete="new-password" placeholder="github_pat_… o ghp_…" @unless($githubConfigured) required @endunless>
                    </div>
                    <div class="row" style="justify-content:flex-end;gap:8px">
                        <button class="btn btn-primary btn-sm">Guardar token</button>
                        @if($githubConfigured)
                            <button formaction="{{ route('dashboard.repositories.sync') }}" class="btn btn-sm">🔄 Sincronizar ahora</button>
                        @endif
                    </div>
                </form>
                <p class="muted tiny" style="margin-top:8px">🔒 El token se guarda cifrado. Solo se usa para leer la lista de repositorios.</p>
            </div>
        </section>
    </div>
</div>

@push('scripts')
<script>
    (function(){
        const tabs = document.querySelectorAll('.cfg-tab');
        const show = id => {
            tabs.forEach(t => t.classList.toggle('on', t.dataset.tab === id));
            document.querySelectorAll('.cfg-panel').forEach(p => p.classList.toggle('on', p.id === 'p-'+id));
            history.replaceState(null, '', '#'+id);
        };
        tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.tab)));
        const h = location.hash.replace('#','');
        if(h && document.getElementById('p-'+h)) show(h);
    })();

    // ── Estado / QR del servidor de WhatsApp ──
    (function(){
        const badge = document.getElementById('wa-badge-txt');
        const dot   = document.getElementById('wa-dot');
        if(!badge) return;
        const qrBtn = document.getElementById('wa-qr-btn');
        const qrBox = document.getElementById('wa-qr-box');
        const qrImg = document.getElementById('wa-qr-img');
        const qrHint= document.getElementById('wa-qr-hint');
        let wantQr = false;
        const setDot = c => { dot.style.background = c; dot.style.boxShadow = '0 0 8px '+c; };

        async function poll(){
            try{
                const r = await fetch('{{ route('dashboard.alerts.wa.status') }}', {headers:{Accept:'application/json'}});
                const d = await r.json();
                if(!d.configured){ badge.textContent='sin servidor'; setDot('var(--muted)'); return; }
                if(d.connected){
                    badge.textContent = 'Conectado' + (d.session ? ' · '+d.session : ''); setDot('var(--ok)');
                    if(qrBox){ qrBox.classList.add('hidden'); } wantQr=false;
                } else if(!d.reachable){
                    badge.textContent='servidor no responde'; setDot('var(--bad)');
                    if(wantQr && qrHint){ qrHint.textContent='No se puede contactar el servidor de WhatsApp. Revisa que esté corriendo (pm2 status).'; }
                } else {
                    badge.textContent='esperando vinculación'; setDot('var(--warn)');
                }
                if(wantQr && d.qr && qrImg){
                    qrImg.src = d.qr; qrImg.style.display=''; qrHint.textContent='Escanéalo antes de que caduque.';
                } else if(wantQr && d.reachable && !d.connected && !d.qr && qrHint){
                    // Servidor vivo pero sin QR: casi siempre el navegador no arrancó.
                    qrImg && (qrImg.style.display='none');
                    qrHint.innerHTML = d.error
                        ? ('⚠️ El navegador no arrancó en el servidor:<br><span style="color:var(--bad)">'+d.error.replace(/[<>]/g,'')+'</span>')
                        : 'Generando el código… (arrancando el navegador en el servidor)';
                }
            }catch(e){ badge.textContent='error de red'; setDot('var(--bad)'); }
        }
        qrBtn && qrBtn.addEventListener('click', async () => {
            wantQr = true; qrBox.classList.remove('hidden'); qrHint.textContent='Generando el código…';
            try{ await fetch('{{ route('dashboard.alerts.wa.qr') }}', {method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}}); }catch(e){}
            poll();
        });
        poll();
        setInterval(poll, 5000);
    })();
</script>
@endpush
@endsection
