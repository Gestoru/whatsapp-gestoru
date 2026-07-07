@extends('dashboard.layout')
@section('title', 'Optimizador SQL · '.$server->name)
@section('subtitle', 'consultas MySQL con contexto para IA · '.$server->host)

@section('actions')
    <a href="{{ route('dashboard.servers.trends', $server) }}" class="btn btn-ghost btn-sm">← Panel</a>
    <button id="q-refresh" class="btn btn-sm">🔄 Actualizar</button>
@endsection

@section('content')
    <h1 style="margin-bottom:4px">🧠 Optimizador de consultas</h1>
    <p class="muted tiny">Las consultas que más carga le generan a MySQL, con diagnóstico automático, usuario que las ejecuta, estructura de sus tablas y un botón que copia todo el contexto <b>listo para pegárselo a una IA</b> y pedirle el plan de optimización.</p>

    <div id="q-body" data-url="{{ route('dashboard.servers.queries.panel', $server) }}">
        <div class="list-card" style="padding:26px;text-align:center;margin-top:14px">
            <span class="spin"></span>
            <div class="muted tiny" style="margin-top:10px">Leyendo performance_schema y la estructura de las tablas por SSH… unos segundos.</div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
const qBody = document.getElementById('q-body');
async function loadQueries(){
    qBody.innerHTML = '<div class="list-card" style="padding:26px;text-align:center;margin-top:14px"><span class="spin"></span>'
        + '<div class="muted tiny" style="margin-top:10px">Leyendo performance_schema y la estructura de las tablas por SSH… unos segundos.</div></div>';
    try{
        const r = await fetch(qBody.dataset.url, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        if(!r.ok) throw new Error('HTTP '+r.status);
        qBody.innerHTML = await r.text();
    }catch(e){
        qBody.innerHTML = '<div class="alert alert-bad">No se pudo cargar el reporte ('+e.message+'). '
            + '<a href="#" onclick="loadQueries();return false" style="text-decoration:underline">Reintentar</a></div>';
    }
}
document.getElementById('q-refresh').addEventListener('click', loadQueries);
loadQueries();

// Copiar el contexto de IA de cada consulta (funciona también sin https)
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if(!btn) return;
    const ta = document.getElementById(btn.dataset.copy);
    if(!ta) return;
    try{
        await navigator.clipboard.writeText(ta.value);
    }catch(_){
        ta.style.display='block'; ta.focus(); ta.select();
        document.execCommand('copy');
        ta.style.display='none';
    }
    const orig = btn.textContent;
    btn.textContent = '✅ Copiado — pégalo en tu IA';
    setTimeout(()=>{ btn.textContent = orig; }, 2500);
});
</script>
@endpush
