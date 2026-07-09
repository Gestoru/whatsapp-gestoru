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
    .kanban{display:grid;grid-template-columns:repeat(4,minmax(260px,1fr));gap:14px;align-items:start;overflow-x:auto;padding-bottom:8px}
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
</style>
@endpush

@section('content')
    <h1 style="margin-bottom:4px">🗂️ Tablero de rendimiento</h1>
    <p class="muted tiny">Todo lo que afecta el rendimiento del servidor —picos de CPU y consultas MySQL pesadas— convertido en tarjetas que puedes clasificar. Cada una trae la <b>causa probable</b> y el contexto para pedirle a una IA el plan. Arrastra o usa los botones para moverlas entre columnas; las que no se pueden optimizar (procesos de Docker, etc.) muévelas a <b>🔒 No aplica</b>.</p>

    <div id="board-body" data-panel="{{ route('dashboard.servers.board.panel', $server) }}"
         data-move="{{ url('panel/servidores/'.$server->id.'/tablero') }}">
        <div class="list-card" style="padding:26px;text-align:center;margin-top:14px">
            <span class="spin"></span>
            <div class="muted tiny" style="margin-top:10px">Analizando el servidor y clasificando las incidencias… unos segundos.</div>
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

// Botones de columna según el estado actual
function actionsFor(status){
    const b = (s,l) => '<button data-move="'+s+'">'+l+'</button>';
    switch(status){
        case 'por_revisar': return b('optimizando','🔧 Optimizar') + b('aceptada','🔒 No aplica');
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
        // conservar el enlace "ver" si existe
        const link = acts.querySelector('a');
        acts.innerHTML = actionsFor(target) + (link ? link.outerHTML : '');
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
