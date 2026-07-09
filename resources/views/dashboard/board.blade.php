@extends('dashboard.layout')
@section('title', 'Tablero de rendimiento · '.$server->name)
@section('subtitle', 'incidencias de CPU y MySQL · '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.trends', $server) }}" class="btn btn-ghost btn-sm">← Panel</a>
    <a href="{{ route('dashboard.servers.queries', $server) }}" class="btn btn-sm">🧠 Optimizador SQL</a>
    <button id="board-refresh" class="btn btn-sm">🔄 Actualizar</button>
@endsection

@push('scripts')
<style>
    .kanban{display:grid;grid-template-columns:repeat(5,minmax(235px,1fr));gap:12px;align-items:start;overflow-x:auto;padding-bottom:8px}
    .kcol{background:#0e1630;border:1px solid var(--line);border-radius:14px;min-height:120px}
    .kcol-head{padding:11px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;
        font-weight:700;font-size:14px;position:sticky;top:0;background:#0e1630;border-radius:14px 14px 0 0;z-index:1}
    .kcol-body{padding:10px;display:flex;flex-direction:column;gap:10px;min-height:60px}
    .kcard{background:linear-gradient(180deg,var(--card),var(--bg2));border:1px solid var(--line);border-left-width:3px;
        border-radius:12px;padding:12px 13px}
    .kcard.drag{opacity:.5}
    .kcol.over{outline:2px dashed var(--accent);outline-offset:-4px}
    .kcard h4{margin:0 0 6px;font-size:14px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
    .kcard .cause{font-size:12px;color:var(--muted);line-height:1.6;margin:8px 0}
    .kcard .kmetrics{display:flex;flex-wrap:wrap;gap:5px;margin:8px 0}
    .kchip{font-size:11px;background:#0e1836;border:1px solid var(--line);border-radius:7px;padding:3px 7px;font-family:ui-monospace,monospace}
    .kacts{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px;padding-top:8px;border-top:1px solid var(--line)}
    .kacts button,.kacts a{font-size:11.5px;padding:5px 9px;border-radius:7px;border:1px solid var(--line);
        background:var(--card2);color:var(--text);cursor:pointer;font-weight:600;font-family:inherit;text-decoration:none}
    .kacts button:hover,.kacts a:hover{border-color:var(--accent)}
    .kcount{margin-left:auto;background:#0e1836;border:1px solid var(--line);border-radius:999px;padding:1px 9px;font-size:12px}
    .kempty{color:var(--muted2);font-size:12px;text-align:center;padding:16px 8px}

    /* ── Modal de planeación con IA ─────────────────────────────────────── */
    .pmodal{position:fixed;inset:0;background:#060a18cc;backdrop-filter:blur(4px);z-index:60;display:flex;
        align-items:flex-start;justify-content:center;padding:26px 14px;overflow-y:auto}
    .pmodal[hidden]{display:none}
    .pm-box{background:linear-gradient(180deg,#0e1630,#0b1226);border:1px solid var(--line);border-radius:16px;
        width:min(900px,100%);box-shadow:0 24px 70px #0009;display:flex;flex-direction:column;max-height:calc(100vh - 52px)}
    .pm-head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line)}
    .pm-head h3{margin:0;font-size:15.5px;flex:1;min-width:0}
    .pm-close{background:none;border:1px solid var(--line);color:var(--muted);border-radius:8px;cursor:pointer;
        font-size:15px;padding:3px 10px}
    .pm-close:hover{color:var(--text);border-color:var(--accent)}
    .pm-body{padding:16px 18px;overflow-y:auto;flex:1}
    .pm-phases{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
    .pm-phase{font-size:11.5px;border:1px solid var(--line);border-radius:999px;padding:4px 11px;color:var(--muted);
        display:flex;align-items:center;gap:6px;background:#0e1836}
    .pm-phase.on{color:#c084fc;border-color:#c084fc66}
    .pm-phase.done{color:#22e39b;border-color:#22e39b55}
    .pm-log{font-family:ui-monospace,monospace;font-size:11.5px;color:var(--muted);background:#0a1024;
        border:1px solid var(--line);border-radius:10px;padding:10px 12px;max-height:150px;overflow-y:auto;
        line-height:1.7;margin-bottom:12px}
    .pm-think{margin-bottom:12px}
    .pm-think pre{font-size:11px;color:#94a3c4;max-height:130px;overflow-y:auto;white-space:pre-wrap;
        background:#0a1024;border:1px solid var(--line);border-radius:10px;padding:10px 12px;line-height:1.6;margin:6px 0 0}
    .pm-plan{background:#0a1024;border:1px solid var(--line);border-radius:12px;padding:16px 18px;font-size:13px;
        line-height:1.75;overflow-x:auto}
    .pm-plan h1,.pm-plan h2,.pm-plan h3{margin:14px 0 6px;font-size:14.5px;color:#e2e8ff}
    .pm-plan h1:first-child,.pm-plan h2:first-child{margin-top:0}
    .pm-plan pre{background:#060b1c;border:1px solid var(--line);border-radius:9px;padding:10px 12px;font-size:11.5px;
        overflow-x:auto;line-height:1.6}
    .pm-plan code{background:#1c2748;border-radius:5px;padding:1px 5px;font-size:11.5px;font-family:ui-monospace,monospace}
    .pm-plan pre code{background:none;padding:0}
    .pm-plan ul,.pm-plan ol{padding-left:20px;margin:6px 0}
    .pm-plan blockquote{border-left:3px solid #c084fc66;margin:8px 0;padding:2px 12px;color:var(--muted)}
    .pm-check{display:flex;align-items:center;gap:10px;background:#22e39b14;border:1px solid #22e39b44;
        border-radius:12px;padding:11px 15px;margin:12px 0;font-size:13px;font-weight:600;color:#22e39b}
    .pm-check .big-check{width:26px;height:26px;border-radius:50%;background:#22e39b;color:#04121c;display:flex;
        align-items:center;justify-content:center;font-size:15px;animation:pmpop .45s cubic-bezier(.2,1.6,.4,1)}
    @keyframes pmpop{0%{transform:scale(0)}100%{transform:scale(1)}}
    .pm-chat{border-top:1px solid var(--line);margin-top:14px;padding-top:12px}
    .pm-msgs{display:flex;flex-direction:column;gap:8px;max-height:280px;overflow-y:auto;margin-bottom:10px}
    .pm-msg{border-radius:11px;padding:9px 13px;font-size:12.5px;line-height:1.65;max-width:85%;white-space:pre-wrap;word-break:break-word}
    .pm-msg.user{align-self:flex-end;background:#1d2a55;border:1px solid #31408066}
    .pm-msg.ai{align-self:flex-start;background:#101a3a;border:1px solid var(--line)}
    .pm-msg .who{font-size:10px;opacity:.55;display:block;margin-bottom:3px}
    .pm-input{display:flex;gap:8px}
    .pm-input textarea{flex:1;background:#0a1024;border:1px solid var(--line);border-radius:10px;color:var(--text);
        padding:9px 12px;font-size:12.5px;font-family:inherit;resize:vertical;min-height:42px;max-height:120px}
    .pm-input textarea:focus{outline:none;border-color:#c084fc88}
    .pm-foot{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:12px 18px;border-top:1px solid var(--line)}
    .pm-setup{background:#101a3a;border:1px solid var(--line);border-radius:12px;padding:14px 16px;margin-bottom:12px}
    .pm-setup label{display:block;font-size:11.5px;color:var(--muted);margin:8px 0 4px}
    .pm-setup input,.pm-setup select{width:100%;background:#0a1024;border:1px solid var(--line);border-radius:9px;
        color:var(--text);padding:8px 11px;font-size:12.5px}
    /* Selector de modo de conexión de IA */
    .pm-modes{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin:9px 0 4px}
    .pm-mode{display:flex;align-items:center;gap:10px;text-align:left;background:#0a1024;border:1px solid var(--line);
        border-radius:11px;padding:11px 13px;cursor:pointer;color:var(--text);font-family:inherit;transition:border-color .15s}
    .pm-mode:hover{border-color:#c084fc66}
    .pm-mode.on{border-color:#c084fc;background:#c084fc12;box-shadow:0 0 0 1px #c084fc55 inset}
    .pm-mode-ic{font-size:20px;flex:none}
    .pm-mode-tx{display:flex;flex-direction:column;gap:1px;min-width:0}
    .pm-mode-tx b{font-size:12.5px}
    @media(max-width:560px){.pm-modes{grid-template-columns:1fr}}
    /* Buscador de repositorios (combobox) */
    .pm-combo{position:relative}
    .pm-combo-list{margin-top:6px;max-height:190px;overflow-y:auto;border:1px solid var(--line);border-radius:9px;
        background:#0a1024}
    .pm-combo-item{padding:8px 12px;font-size:12.5px;cursor:pointer;border-bottom:1px solid #ffffff08;
        display:flex;align-items:center;gap:8px}
    .pm-combo-item:last-child{border-bottom:none}
    .pm-combo-item:hover{background:#c084fc14}
    .pm-combo-item.on{background:#c084fc22;color:#e9d5ff}
    .pm-combo-item .lang{margin-left:auto;font-size:10.5px;color:var(--muted2)}
    .pm-combo-empty{padding:12px;font-size:12px;color:var(--muted2);text-align:center}
    .pm-err{background:#ff4d6d14;border:1px solid #ff4d6d44;color:#fca5a5;border-radius:10px;padding:10px 14px;
        font-size:12.5px;margin:10px 0;line-height:1.6}
    .pm-spin{display:inline-block;width:12px;height:12px;border:2px solid #c084fc44;border-top-color:#c084fc;
        border-radius:50%;animation:pmspin .8s linear infinite;vertical-align:-2px}
    @keyframes pmspin{to{transform:rotate(360deg)}}
</style>
@endpush

@section('content')
    <h1 style="margin-bottom:4px">🗂️ Tablero de rendimiento</h1>
    <p class="muted tiny">Todo lo que afecta el rendimiento del servidor —picos de CPU y consultas MySQL pesadas— convertido en tarjetas que puedes clasificar: <b>📥 por revisar → 🧠 en planeación → 🔧 en optimización → ✅ resueltas / 🔒 no aplica</b>. En las consultas MySQL, <b>🧠 Planear</b> conecta con tu GitHub, investiga el código del proyecto y genera el plan de optimización con IA en vivo.</p>

    <div id="board-body" data-panel="{{ route('dashboard.servers.board.panel', $server) }}"
         data-move="{{ url('panel/servidores/'.$server->id.'/tablero') }}">
        <div class="list-card" style="padding:26px;text-align:center;margin-top:14px">
            <span class="spin"></span>
            <div class="muted tiny" style="margin-top:10px">Analizando el servidor y clasificando las incidencias… unos segundos.</div>
        </div>
    </div>

    {{-- ── Modal: plan de optimización con IA ─────────────────────────────── --}}
    <div id="plan-modal" class="pmodal" hidden>
        <div class="pm-box">
            <div class="pm-head">
                <span style="font-size:19px">🧠</span>
                <h3>Plan de optimización con IA <span id="pm-sub" class="muted tiny" style="font-weight:400"></span></h3>
                <button type="button" class="pm-close" id="pm-close">✕ Cerrar</button>
            </div>
            <div class="pm-body">
                {{-- Configuración previa: conexión de IA y mapeo del repositorio --}}
                <div id="pm-setup" class="pm-setup" hidden>
                    {{-- Conexión con la IA: dos modos --}}
                    <div id="pm-setup-ai" hidden>
                        <div class="tiny" style="font-weight:600">🤖 Conectar la IA <span class="muted" style="font-weight:400">— elige cómo</span></div>
                        <div class="pm-modes">
                            <button type="button" class="pm-mode" data-mode="api_key">
                                <span class="pm-mode-ic">🔑</span>
                                <span class="pm-mode-tx"><b>Clave de API</b><span class="tiny muted">Pagas por uso a la API de Anthropic</span></span>
                            </button>
                            <button type="button" class="pm-mode" data-mode="oauth">
                                <span class="pm-mode-ic">👤</span>
                                <span class="pm-mode-tx"><b>Cuenta de Claude</b><span class="tiny muted">Suscripción Pro, Max, Team o Enterprise</span></span>
                            </button>
                        </div>

                        {{-- Panel: clave de API --}}
                        <div id="pm-pane-key" hidden>
                            <label>Clave de la API de Anthropic (se guarda cifrada en el panel)</label>
                            <div style="display:flex;gap:8px">
                                <input type="password" id="pm-key" placeholder="sk-ant-…" autocomplete="off">
                                <button class="btn btn-sm" id="pm-key-save">Guardar</button>
                            </div>
                        </div>

                        {{-- Panel: cuenta de Claude (OAuth, como Claude Code) --}}
                        <div id="pm-pane-oauth" hidden>
                            <div id="pm-oauth-connected" hidden>
                                <div class="pm-check" style="margin:8px 0">
                                    <span class="big-check">✓</span>
                                    <span>Conectado con tu cuenta de Claude <b id="pm-oauth-acct" class="tiny" style="color:#22e39b"></b></span>
                                </div>
                                <button class="btn btn-ghost btn-sm" id="pm-oauth-disconnect">Desconectar cuenta</button>
                            </div>
                            <div id="pm-oauth-connect">
                                <label>Conecta tu cuenta con suscripción (igual que Claude Code). Se abrirá la página de Claude para autorizar; luego copia el código que te muestran y pégalo aquí.</label>
                                <button class="btn btn-sm" id="pm-oauth-open" style="margin:2px 0">🔗 Abrir autorización de Claude</button>
                                <div id="pm-oauth-step2" hidden style="margin-top:8px">
                                    <label>Pega el código que te mostró Claude:</label>
                                    <div style="display:flex;gap:8px">
                                        <input type="text" id="pm-oauth-code" placeholder="código#estado" autocomplete="off">
                                        <button class="btn btn-sm" id="pm-oauth-save">Conectar</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Mapeo del repositorio, con buscador --}}
                    <div id="pm-setup-repo" hidden style="margin-top:12px">
                        <div class="tiny" style="font-weight:600">🔗 ¿A qué proyecto pertenece esta consulta?</div>
                        <label>La base de datos <b id="pm-db" style="color:#c084fc">—</b> se mapeará a este repositorio de GitHub (se recuerda para las próximas consultas de la misma base):</label>
                        <div class="pm-combo">
                            <input type="text" id="pm-repo-search" placeholder="🔎 Busca tu proyecto por nombre…" autocomplete="off">
                            <div class="pm-combo-list" id="pm-repo-list"></div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
                            <span class="tiny" id="pm-repo-chosen" style="color:#c084fc"></span>
                            <span style="flex:1"></span>
                            <button class="btn btn-sm" id="pm-repo-save" disabled>Guardar mapeo</button>
                        </div>
                        <div class="tiny muted" id="pm-repo-hint" style="margin-top:6px"></div>
                    </div>
                </div>

                {{-- Estado de conexión de IA cuando ya está lista (con opción de cambiar) --}}
                <div id="pm-ai-status" class="tiny muted" hidden style="margin-bottom:10px"></div>

                {{-- Fases del proceso --}}
                <div class="pm-phases" id="pm-phases" hidden>
                    <span class="pm-phase" data-phase="repo">🔗 Repositorio</span>
                    <span class="pm-phase" data-phase="investigando">🔍 Investigación del código</span>
                    <span class="pm-phase" data-phase="generando">🧠 Generación del plan</span>
                    <span class="pm-phase" data-phase="listo">✅ Listo</span>
                </div>

                <div class="pm-log" id="pm-log" hidden></div>

                <details class="pm-think" id="pm-think" hidden>
                    <summary class="tiny" style="cursor:pointer;color:#94a3c4">🧩 Razonamiento de la IA (en vivo)</summary>
                    <pre id="pm-think-body"></pre>
                </details>

                <div class="pm-err" id="pm-error" hidden></div>

                <div class="pm-check" id="pm-check" hidden>
                    <span class="big-check">✓</span>
                    <span>Plan terminado. Léelo abajo y, si quieres ajustarlo, escríbele a la IA en el chat.</span>
                </div>

                <div class="pm-plan" id="pm-plan" hidden></div>

                {{-- Chat para leer/ajustar el plan --}}
                <div class="pm-chat" id="pm-chat" hidden>
                    <div class="tiny" style="font-weight:600;margin-bottom:8px">💬 Ajustar el plan con la IA</div>
                    <div class="pm-msgs" id="pm-msgs"></div>
                    <div class="pm-input">
                        <textarea id="pm-msg" placeholder="Ej.: agrega el SQL para revertir el índice, o explícame el paso 2…"></textarea>
                        <button class="btn btn-sm" id="pm-send">Enviar</button>
                    </div>
                </div>
            </div>
            <div class="pm-foot">
                <button class="btn btn-sm" id="pm-generate" hidden>🧠 Generar plan</button>
                <button class="btn btn-ghost btn-sm" id="pm-regenerate" hidden>🔄 Regenerar plan</button>
                <span style="flex:1"></span>
                <span class="tiny muted" id="pm-pushed" hidden></span>
                <button class="btn btn-sm" id="pm-publish" hidden style="border-color:#22e39b66;color:#22e39b">🚀 Subir solución a GitHub</button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name=csrf-token]')?.content || '';
const boardBody = document.getElementById('board-body');

async function loadBoard(){
    boardBody.innerHTML = '<div class="list-card" style="padding:26px;text-align:center;margin-top:14px"><span class="spin"></span>'
        + '<div class="muted tiny" style="margin-top:10px">Analizando el servidor y clasificando las incidencias… unos segundos.</div></div>';
    try{
        const r = await fetch(boardBody.dataset.panel, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!r.ok) throw new Error('HTTP '+r.status);
        boardBody.innerHTML = await r.text();
        wireBoard();
    }catch(e){
        boardBody.innerHTML = '<div class="alert alert-bad">No se pudo cargar el tablero ('+e.message+'). '
            + '<a href="#" onclick="loadBoard();return false" style="text-decoration:underline">Reintentar</a></div>';
    }
}

async function moveIssue(id, status){
    try{
        const r = await fetch(boardBody.dataset.move + '/' + id + '/mover', {
            method:'POST',
            headers:{'X-CSRF-TOKEN':CSRF, 'Content-Type':'application/json', Accept:'application/json'},
            body: JSON.stringify({status})
        });
        const d = await r.json();
        return d.ok;
    }catch(_){ return false; }
}

// Botones de columna según el estado actual (y el tipo de incidencia)
function actionsFor(status, kind, issueId){
    const b = (s,l) => '<button data-move="'+s+'">'+l+'</button>';
    // «Planear» solo aplica a consultas MySQL (conecta con GitHub + IA)
    const plan = (kind === 'mysql_query' && ['por_revisar','planeando'].includes(status))
        ? '<button data-plan="'+issueId+'" style="border-color:#c084fc66;color:#c084fc">🧠 Planear</button>' : '';
    switch(status){
        case 'por_revisar': return plan + b('optimizando','🔧 Optimizar') + b('aceptada','🔒 No aplica');
        case 'planeando':   return plan + b('optimizando','🔧 Optimizar') + b('por_revisar','↩ Volver') + b('aceptada','🔒 No aplica');
        case 'optimizando': return b('resuelta','✅ Resuelta') + b('aceptada','🔒 No aplica') + b('por_revisar','↩ Volver');
        case 'resuelta':    return b('por_revisar','↩ Reabrir');
        case 'aceptada':    return b('por_revisar','↩ Reabrir');
    }
    return '';
}

function wireBoard(){
    // Botones
    boardBody.querySelectorAll('.kacts button[data-move]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const card = btn.closest('.kcard');
            const target = btn.dataset.move;
            if(await moveIssue(card.dataset.id, target)) relocate(card, target);
        });
    });
    // Arrastrar y soltar
    boardBody.querySelectorAll('.kcard').forEach(card => {
        card.setAttribute('draggable','true');
        card.addEventListener('dragstart', e => { card.classList.add('drag'); e.dataTransfer.setData('text/plain', card.dataset.id); });
        card.addEventListener('dragend', () => card.classList.remove('drag'));
    });
    boardBody.querySelectorAll('.kcol').forEach(col => {
        col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('over'); });
        col.addEventListener('dragleave', () => col.classList.remove('over'));
        col.addEventListener('drop', async e => {
            e.preventDefault(); col.classList.remove('over');
            const id = e.dataTransfer.getData('text/plain');
            const card = boardBody.querySelector('.kcard[data-id="'+id+'"]');
            const target = col.dataset.col;
            if(card && await moveIssue(id, target)) relocate(card, target);
        });
    });
}

function relocate(card, target){
    const body = boardBody.querySelector('.kcol[data-col="'+target+'"] .kcol-body');
    if(!body) return;
    const empty = body.querySelector('.kempty'); if(empty) empty.remove();
    card.dataset.status = target;
    const acts = card.querySelector('.kacts');
    if(acts){
        // conservar el enlace "ver" y el botón de copiar contexto
        const link = acts.querySelector('a');
        const copy = acts.querySelector('[data-copy]');
        acts.innerHTML = actionsFor(target, card.dataset.kind, card.dataset.id)
            + (link ? link.outerHTML : '') + (copy ? copy.outerHTML : '');
    }
    body.prepend(card);
    wireBoard();
    updateCounts();
}

function updateCounts(){
    boardBody.querySelectorAll('.kcol').forEach(col => {
        const n = col.querySelectorAll('.kcard').length;
        const c = col.querySelector('.kcount'); if(c) c.textContent = n;
        const body = col.querySelector('.kcol-body');
        if(n === 0 && !body.querySelector('.kempty')){
            const d = document.createElement('div'); d.className='kempty'; d.textContent='—'; body.appendChild(d);
        }
    });
}

document.getElementById('board-refresh').addEventListener('click', loadBoard);
loadBoard();

// ════════════════════════════════════════════════════════════════════════════
// Modal de planeación con IA: mapeo del repo → investigación en GitHub →
// plan generado en vivo → chulito verde → chat de ajustes → subir a GitHub.
// ════════════════════════════════════════════════════════════════════════════
const $id = s => document.getElementById(s);
const PM = { issueId:null, state:null, es:null, raw:'', streaming:false,
    aiMode:'api_key', forceAiSetup:false, selectedRepo:null };
const planUrl = (id, sfx) => boardBody.dataset.move + '/' + id + '/plan' + (sfx || '');
const AI_URLS = {
    key:        @json(route('dashboard.ai.key')),
    mode:       @json(route('dashboard.ai.mode')),
    start:      @json(route('dashboard.ai.oauth.start')),
    finish:     @json(route('dashboard.ai.oauth.finish')),
    disconnect: @json(route('dashboard.ai.oauth.disconnect')),
};
const jhead = {'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json',Accept:'application/json'};

document.addEventListener('click', e => {
    const btn = e.target.closest('[data-plan]');
    if(btn) openPlan(btn.dataset.plan);
});
$id('pm-close').addEventListener('click', closePlan);
$id('plan-modal').addEventListener('click', e => { if(e.target === $id('plan-modal')) closePlan(); });

function show(id, on){ $id(id).hidden = !on; }
function showError(msg){ $id('pm-error').textContent = '⚠️ ' + msg; show('pm-error', true); }
function log(line){ const l = $id('pm-log'); l.innerHTML += line.replace(/</g,'&lt;') + '<br>'; l.scrollTop = l.scrollHeight; }
function setPhase(name, cls){
    const el = document.querySelector('.pm-phase[data-phase="'+name+'"]');
    if(el){ el.classList.remove('on','done'); if(cls) el.classList.add(cls); }
}

function resetModal(){
    ['pm-setup','pm-setup-ai','pm-ai-status','pm-setup-repo','pm-phases','pm-log','pm-think','pm-error',
     'pm-check','pm-plan','pm-chat','pm-generate','pm-regenerate','pm-publish','pm-pushed'].forEach(i => show(i, false));
    $id('pm-log').innerHTML = ''; $id('pm-think-body').textContent = '';
    $id('pm-plan').innerHTML = ''; $id('pm-msgs').innerHTML = '';
    document.querySelectorAll('.pm-phase').forEach(p => p.classList.remove('on','done'));
    PM.raw = ''; PM.streaming = false; PM.forceAiSetup = false; PM.selectedRepo = null;
}

function closePlan(){
    if(PM.es){ PM.es.close(); PM.es = null; }
    show('plan-modal', false);
    loadBoard();   // refresca badges y columnas
}

async function openPlan(issueId){
    PM.issueId = issueId;
    resetModal();
    show('plan-modal', true);
    $id('pm-sub').textContent = '· incidencia #' + issueId;
    try{
        const r = await fetch(planUrl(issueId, ''), {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!r.ok) throw new Error('HTTP ' + r.status);
        PM.state = await r.json();
    }catch(e){ showError('No se pudo cargar el estado del plan (' + e.message + ')'); return; }
    renderState();
}

function renderState(){
    const s = PM.state;
    $id('pm-db').textContent = s.db || '—';
    PM.aiMode = s.ai_mode || 'api_key';

    const aiOk = s.ai_configured, repoOk = !!s.repo;
    const showAi = !aiOk || PM.forceAiSetup;
    const pendiente = !aiOk || !repoOk;

    // Conexión de IA: selector de modo (o resumen si ya está lista)
    show('pm-setup-ai', showAi);
    if(showAi) renderAiModes();
    show('pm-ai-status', aiOk && !PM.forceAiSetup);
    if(aiOk && !PM.forceAiSetup){
        const label = PM.aiMode === 'oauth'
            ? '👤 Cuenta de Claude' + (s.oauth_account ? ' · ' + s.oauth_account : '')
            : '🔑 Clave de API';
        $id('pm-ai-status').innerHTML = 'Conexión de IA: <b>' + label + '</b> · '
            + '<a href="#" id="pm-ai-change" style="color:#c084fc">cambiar</a>';
        $id('pm-ai-change').onclick = ev => { ev.preventDefault(); PM.forceAiSetup = true; renderState(); };
    }

    // Mapeo del repositorio (con buscador)
    show('pm-setup-repo', !repoOk);
    if(!repoOk) renderRepoCombo();

    show('pm-setup', showAi || !repoOk);

    const p = s.plan;
    if(p && p.status === 'listo' && p.plan){
        PM.raw = p.plan;
        show('pm-check', true);
        renderPlan(p.plan); show('pm-plan', true);
        show('pm-chat', true); renderChat(p.chat || []);
        show('pm-regenerate', !pendiente);
        show('pm-publish', !pendiente && s.github_configured);
        if(p.github_pr_url){
            $id('pm-pushed').innerHTML = '🚀 Publicado' + (p.pushed_at ? ' el ' + p.pushed_at : '')
                + ' · <a href="' + p.github_pr_url + '" target="_blank" rel="noopener" style="color:#a5b4fc">ver pull request</a>';
            show('pm-pushed', true);
        }
    } else {
        if(p && p.status === 'error' && p.error) showError('El último intento falló: ' + p.error);
        show('pm-generate', !pendiente);
    }
}

// ── Conexión de IA: selector de modo (clave de API / cuenta de Claude) ───────
function renderAiModes(){
    const s = PM.state;
    document.querySelectorAll('.pm-mode').forEach(b => b.classList.toggle('on', b.dataset.mode === PM.aiMode));
    show('pm-pane-key', PM.aiMode === 'api_key');
    show('pm-pane-oauth', PM.aiMode === 'oauth');
    if(PM.aiMode === 'oauth'){
        show('pm-oauth-connected', !!s.oauth_connected);
        show('pm-oauth-connect', !s.oauth_connected);
        show('pm-oauth-step2', false);
        if(s.oauth_connected) $id('pm-oauth-acct').textContent = s.oauth_account || '';
    }
}

document.querySelectorAll('.pm-mode').forEach(b => b.addEventListener('click', async () => {
    const mode = b.dataset.mode;
    PM.aiMode = mode;
    renderAiModes();
    // Si el modo elegido ya está aprovisionado, se activa directo.
    const listo = (mode === 'oauth' && PM.state.oauth_connected) || (mode === 'api_key' && PM.state.has_api_key);
    if(listo){
        await fetch(AI_URLS.mode, {method:'POST', headers:jhead, body: JSON.stringify({mode})});
        PM.forceAiSetup = false; openPlan(PM.issueId);
    }
}));

$id('pm-key-save').addEventListener('click', async () => {
    const key = $id('pm-key').value.trim();
    if(!key) return;
    const r = await fetch(AI_URLS.key, {method:'POST', headers:jhead, body: JSON.stringify({api_key:key})});
    if(r.ok){ $id('pm-key').value=''; PM.forceAiSetup = false; openPlan(PM.issueId); }
    else showError('No se pudo guardar la clave.');
});

// ── Cuenta de Claude (OAuth, como Claude Code) ───────────────────────────────
$id('pm-oauth-open').addEventListener('click', async () => {
    const btn = $id('pm-oauth-open'); btn.disabled = true;
    try{
        const r = await fetch(AI_URLS.start, {method:'POST', headers:jhead});
        const d = await r.json();
        if(d.ok && d.url){ window.open(d.url, '_blank', 'noopener'); show('pm-oauth-step2', true); $id('pm-oauth-code').focus(); }
        else showError('No se pudo iniciar la conexión con Claude.');
    }catch(e){ showError('No se pudo iniciar la conexión (' + e.message + ').'); }
    btn.disabled = false;
});

$id('pm-oauth-save').addEventListener('click', async () => {
    const code = $id('pm-oauth-code').value.trim();
    if(!code) return;
    const btn = $id('pm-oauth-save'); btn.disabled = true; btn.innerHTML = '<span class="pm-spin"></span>';
    try{
        const r = await fetch(AI_URLS.finish, {method:'POST', headers:jhead, body: JSON.stringify({code})});
        const d = await r.json();
        if(d.ok){ $id('pm-oauth-code').value=''; PM.forceAiSetup = false; openPlan(PM.issueId); }
        else showError(d.error || 'No se pudo conectar la cuenta.');
    }catch(e){ showError('No se pudo conectar (' + e.message + ').'); }
    btn.disabled = false; btn.textContent = 'Conectar';
});

$id('pm-oauth-disconnect').addEventListener('click', async () => {
    await fetch(AI_URLS.disconnect, {method:'POST', headers:jhead});
    openPlan(PM.issueId);
});

// ── Buscador de repositorios (combobox) ──────────────────────────────────────
function renderRepoCombo(){
    PM.selectedRepo = null;
    $id('pm-repo-search').value = '';
    $id('pm-repo-chosen').textContent = '';
    $id('pm-repo-save').disabled = true;
    const repos = PM.state.repos || [];
    $id('pm-repo-hint').textContent = repos.length ? ''
        : 'No hay repositorios sincronizados. Ve al módulo Repositorios, conecta GitHub y sincroniza; luego vuelve aquí.';
    filterRepos('');
}
function filterRepos(q){
    const repos = PM.state.repos || [];
    q = (q || '').toLowerCase().trim();
    const matches = repos.filter(r => r.full_name.toLowerCase().includes(q)).slice(0, 50);
    const list = $id('pm-repo-list');
    if(!matches.length){ list.innerHTML = '<div class="pm-combo-empty">Sin coincidencias</div>'; return; }
    list.innerHTML = matches.map(r =>
        '<div class="pm-combo-item' + (PM.selectedRepo === r.id ? ' on' : '') + '" data-id="' + r.id + '">'
        + '<span>' + r.full_name.replace(/</g,'&lt;') + '</span>'
        + (r.language ? '<span class="lang">' + r.language + '</span>' : '') + '</div>').join('');
    list.querySelectorAll('.pm-combo-item').forEach(it => it.addEventListener('click', () => {
        PM.selectedRepo = parseInt(it.dataset.id, 10);
        const repo = repos.find(r => r.id === PM.selectedRepo);
        $id('pm-repo-chosen').textContent = repo ? '✓ ' + repo.full_name : '';
        $id('pm-repo-save').disabled = false;
        list.querySelectorAll('.pm-combo-item').forEach(x => x.classList.toggle('on', x === it));
    }));
}
$id('pm-repo-search').addEventListener('input', e => filterRepos(e.target.value));

$id('pm-repo-save').addEventListener('click', async () => {
    if(!PM.selectedRepo) return;
    const r = await fetch(planUrl(PM.issueId, '/repo'), {method:'POST', headers:jhead,
        body: JSON.stringify({repository_id: PM.selectedRepo})});
    if(r.ok) openPlan(PM.issueId);
    else showError('No se pudo guardar el mapeo.');
});

// ── Generación del plan EN VIVO (Server-Sent Events) ─────────────────────────
$id('pm-generate').addEventListener('click', startGeneration);
$id('pm-regenerate').addEventListener('click', startGeneration);

function startGeneration(){
    ['pm-generate','pm-regenerate','pm-check','pm-error','pm-chat','pm-publish','pm-pushed','pm-setup'].forEach(i => show(i, false));
    $id('pm-plan').innerHTML = ''; $id('pm-log').innerHTML = ''; $id('pm-think-body').textContent = '';
    ['pm-phases','pm-log','pm-plan'].forEach(i => show(i, true));
    setPhase('repo','done'); setPhase('investigando','on');
    setPhase('generando',''); setPhase('listo','');
    PM.raw = ''; PM.streaming = true;
    log('🔗 Repositorio: ' + (PM.state.repo ? PM.state.repo.full_name : '—'));

    const es = PM.es = new EventSource(planUrl(PM.issueId, '/stream'));
    es.addEventListener('paso',    e => log('• ' + JSON.parse(e.data).m));
    es.addEventListener('archivo', e => log('📄 Leyendo ' + JSON.parse(e.data).m));
    es.addEventListener('fase', e => {
        if(JSON.parse(e.data).fase === 'generando'){ setPhase('investigando','done'); setPhase('generando','on'); }
    });
    es.addEventListener('razonando', e => {
        show('pm-think', true);
        const b = $id('pm-think-body');
        b.textContent += JSON.parse(e.data).m; b.scrollTop = b.scrollHeight;
    });
    es.addEventListener('texto', e => {
        PM.raw += JSON.parse(e.data).m;
        renderPlan(PM.raw);
        const bd = document.querySelector('.pm-body'); bd.scrollTop = bd.scrollHeight;
    });
    es.addEventListener('listo', e => {
        const d = JSON.parse(e.data);
        PM.raw = d.plan || PM.raw; renderPlan(PM.raw);
        setPhase('generando','done'); setPhase('listo','done');
        es.close(); PM.es = null; PM.streaming = false;
        show('pm-check', true); show('pm-chat', true);
        show('pm-regenerate', true);
        if(PM.state.github_configured) show('pm-publish', true);
        $id('pm-check').scrollIntoView({behavior:'smooth', block:'center'});
    });
    es.addEventListener('error', e => {
        let msg = 'Se perdió la conexión durante la generación. Reintenta.';
        try{ if(e.data) msg = JSON.parse(e.data).m; }catch(_){}
        if(PM.streaming){ showError(msg); show('pm-generate', true); }
        if(PM.es){ PM.es.close(); PM.es = null; } PM.streaming = false;
    });
}

// ── Chat para leer/ajustar el plan ───────────────────────────────────────────
$id('pm-send').addEventListener('click', sendChat);
$id('pm-msg').addEventListener('keydown', e => {
    if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); sendChat(); }
});

function addMsg(role, text){
    const d = document.createElement('div');
    d.className = 'pm-msg ' + (role === 'user' ? 'user' : 'ai');
    d.innerHTML = '<span class="who">' + (role === 'user' ? 'Tú' : '🧠 IA') + '</span><span class="body"></span>';
    d.querySelector('.body').textContent = text;
    $id('pm-msgs').appendChild(d);
    $id('pm-msgs').scrollTop = $id('pm-msgs').scrollHeight;
    return d;
}
function renderChat(history){
    $id('pm-msgs').innerHTML = '';
    history.forEach(m => addMsg(m.role, m.content));
}

async function sendChat(){
    const ta = $id('pm-msg'); const msg = ta.value.trim();
    if(!msg || PM.streaming) return;
    ta.value = ''; addMsg('user', msg);
    const ai = addMsg('ai', ''); ai.querySelector('.body').innerHTML = '<span class="pm-spin"></span>';
    PM.streaming = true;
    try{
        const r = await fetch(planUrl(PM.issueId, '/chat'), {method:'POST',
            headers:{'X-CSRF-TOKEN':CSRF,'Content-Type':'application/json'},
            body: JSON.stringify({message:msg})});
        if(!r.ok || !r.body) throw new Error('HTTP ' + r.status);

        const reader = r.body.getReader(); const dec = new TextDecoder();
        let buf = '', text = '';
        const handle = (ev, d) => {
            if(ev === 'texto'){
                text += d.m; ai.querySelector('.body').textContent = text;
                $id('pm-msgs').scrollTop = $id('pm-msgs').scrollHeight;
            } else if(ev === 'texto_fin_visible'){
                ai.querySelector('.body').textContent = text + '\n\n✏️ Actualizando el plan…';
            } else if(ev === 'listo'){
                ai.querySelector('.body').textContent = d.reply || text;
                if(d.plan_actualizado && d.plan){
                    PM.raw = d.plan; renderPlan(PM.raw);
                    log('✏️ Plan actualizado desde el chat');
                }
            } else if(ev === 'error'){
                ai.querySelector('.body').textContent = '⚠️ ' + (d.m || 'Error al contactar la IA.');
            }
        };
        while(true){
            const {done, value} = await reader.read();
            if(done) break;
            buf += dec.decode(value, {stream:true});
            let i;
            while((i = buf.indexOf('\n\n')) >= 0){
                const chunk = buf.slice(0, i); buf = buf.slice(i + 2);
                let ev = 'message', data = null;
                chunk.split('\n').forEach(line => {
                    if(line.startsWith('event: ')) ev = line.slice(7).trim();
                    else if(line.startsWith('data: ')){ try{ data = JSON.parse(line.slice(6)); }catch(_){} }
                });
                if(data !== null) handle(ev, data);
            }
        }
    }catch(e){
        ai.querySelector('.body').textContent = '⚠️ No se pudo contactar la IA (' + e.message + ').';
    }
    PM.streaming = false;
}

// ── Subir la solución a GitHub (rama + archivo + pull request) ──────────────
$id('pm-publish').addEventListener('click', async () => {
    const btn = $id('pm-publish');
    btn.disabled = true; btn.innerHTML = '<span class="pm-spin"></span> Publicando…';
    try{
        const r = await fetch(planUrl(PM.issueId, '/publicar'), {method:'POST',
            headers:{'X-CSRF-TOKEN':CSRF, Accept:'application/json'}});
        const d = await r.json();
        if(d.ok){
            $id('pm-pushed').innerHTML = '🚀 Publicado ahora · <a href="' + d.pr_url
                + '" target="_blank" rel="noopener" style="color:#a5b4fc">ver pull request</a>';
            show('pm-pushed', true);
            btn.innerHTML = '✅ Publicado en GitHub';
        } else {
            showError(d.error || 'No se pudo publicar.');
            btn.disabled = false; btn.innerHTML = '🚀 Subir solución a GitHub';
        }
    }catch(e){
        showError('No se pudo publicar (' + e.message + ').');
        btn.disabled = false; btn.innerHTML = '🚀 Subir solución a GitHub';
    }
});

// ── Mini-renderizador de Markdown para el plan ───────────────────────────────
function renderPlan(md){
    $id('pm-plan').innerHTML = mdRender(md || '');
    show('pm-plan', true);
}
function mdRender(md){
    const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    let out = '';
    const parts = String(md).split('```');
    for(let i = 0; i < parts.length; i++){
        if(i % 2 === 1){   // bloque de código
            let code = parts[i]; const nl = code.indexOf('\n');
            if(nl >= 0) code = code.slice(nl + 1);   // quita el nombre del lenguaje
            out += '<pre><code>' + esc(code) + '</code></pre>';
            continue;
        }
        let t = esc(parts[i]);
        t = t.replace(/^#### (.*)$/gm,'<h3>$1</h3>')
             .replace(/^### (.*)$/gm,'<h3>$1</h3>')
             .replace(/^## (.*)$/gm,'<h2>$1</h2>')
             .replace(/^# (.*)$/gm,'<h1>$1</h1>')
             .replace(/^&gt; ?(.*)$/gm,'<blockquote>$1</blockquote>')
             .replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>')
             .replace(/`([^`]+)`/g,'<code>$1</code>')
             .replace(/^\s*[-*] (.*)$/gm,'<li>$1</li>')
             .replace(/^\s*(\d+)[\.)] (.*)$/gm,'<li><b>$1.</b> $2</li>');
        t = t.replace(/(?:<li>[\s\S]*?<\/li>\n?)+/g, m => '<ul>' + m.replace(/\n/g,'') + '</ul>');
        t = t.replace(/\n{2,}/g,'<br>').replace(/\n/g,'<br>');
        out += t;
    }
    return out;
}

// Copiar contexto para IA (delegado)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if(!btn) return;
    const ta = document.getElementById(btn.dataset.copy);
    if(!ta) return;
    try{ await navigator.clipboard.writeText(ta.value); }
    catch(_){ ta.style.display='block'; ta.focus(); ta.select(); document.execCommand('copy'); ta.style.display='none'; }
    const t = btn.textContent; btn.textContent = '✅ Copiado'; setTimeout(()=>btn.textContent=t, 2000);
});
</script>
@endpush
