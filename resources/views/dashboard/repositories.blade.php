@extends('dashboard.layout')
@section('title', 'Repositorios')
@section('subtitle', 'todos tus proyectos de GitHub en un solo lugar')

@section('actions')
    @if($configured)
        <form method="POST" action="{{ route('dashboard.repositories.sync') }}" style="display:inline">
            @csrf
            <button class="btn btn-primary btn-sm" title="Trae tus repositorios desde GitHub">🔄 Sincronizar</button>
        </form>
    @endif
    <a href="{{ route('dashboard.config') }}#github" class="btn btn-sm">⚙️ Conectar GitHub</a>
@endsection

@section('content')
<style>
    .chips{display:flex;flex-wrap:wrap;gap:7px;align-items:center}
    .chip{padding:6px 12px;border-radius:999px;border:1px solid var(--line);background:var(--card2);
        color:var(--muted);font-size:13px;font-weight:600;cursor:pointer;transition:.14s;white-space:nowrap}
    .chip:hover{color:var(--text);border-color:var(--line2)}
    .chip.chip-on{color:#fff;background:linear-gradient(145deg,var(--accent),var(--accent2));border-color:transparent}
    .fgroup{display:flex;flex-direction:column;gap:7px;margin-bottom:12px}
    .fgroup .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted2);font-weight:700}
    .vis-badge{font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700}
    .vis-private{background:#3a2f14;color:#f7c76b} .vis-public{background:#12324a;color:#67c7f0}
    .repo-note{color:#9be7b0;font-size:12.5px;margin-top:4px}
    .repo-desc{font-size:13px}
    .topic{font-size:11px;padding:1px 7px;border-radius:6px;background:#1b2450;color:#9fb0e0;margin-right:4px}
</style>

@if(! $configured)
    <div class="card empty">
        <div class="big">📚</div>
        <h1>Conecta tu GitHub</h1>
        <p class="muted" style="margin:10px auto 20px;max-width:520px">
            Vincula GitHub para ver aquí todos los repositorios de tus organizaciones y tu cuenta,
            con filtros, buscador y una nota de documentación por proyecto.
        </p>
        <a href="{{ route('dashboard.config') }}#github" class="btn btn-primary">⚙️ Conectar GitHub</a>
    </div>
@else
    {{-- Resumen --}}
    <div class="stat-grid" style="margin-bottom:16px">
        <div class="stat"><div class="k">Repositorios</div><div class="v">{{ $stats['total'] }}</div></div>
        <div class="stat"><div class="k">Organizaciones / cuentas</div><div class="v">{{ $stats['orgs'] }}</div></div>
        <div class="stat"><div class="k">Privados</div><div class="v">{{ $stats['private'] }}</div></div>
        <div class="stat"><div class="k">Archivados</div><div class="v muted">{{ $stats['archived'] }}</div></div>
    </div>

    @if($repos->isEmpty())
        <div class="card empty"><div class="big">🌱</div><h1>Sin repositorios todavía</h1>
            <p class="muted" style="margin-top:8px">Presiona «🔄 Sincronizar» para traerlos desde GitHub.</p></div>
    @else
        {{-- Filtros --}}
        <div class="card" style="margin-bottom:16px">
            <div class="fgroup">
                <span class="lbl">Organización</span>
                <div class="chips">
                    <span class="chip chip-on" data-group="owner" data-value="">Todas</span>
                    @foreach($owners as $o)
                        <span class="chip" data-group="owner" data-value="{{ strtolower($o) }}">{{ $o }}</span>
                    @endforeach
                </div>
            </div>
            @if($languages->isNotEmpty())
            <div class="fgroup">
                <span class="lbl">Lenguaje</span>
                <div class="chips">
                    <span class="chip chip-on" data-group="lang" data-value="">Todos</span>
                    @foreach($languages as $l)
                        <span class="chip" data-group="lang" data-value="{{ strtolower($l) }}">{{ $l }}</span>
                    @endforeach
                </div>
            </div>
            @endif
            <div class="row" style="gap:22px;flex-wrap:wrap">
                <div class="fgroup" style="margin-bottom:0">
                    <span class="lbl">Visibilidad</span>
                    <div class="chips">
                        <span class="chip chip-on" data-group="vis" data-value="">Todas</span>
                        <span class="chip" data-group="vis" data-value="public">Públicos</span>
                        <span class="chip" data-group="vis" data-value="private">Privados</span>
                    </div>
                </div>
                <div class="fgroup" style="margin-bottom:0">
                    <span class="lbl">Estado</span>
                    <div class="chips">
                        <span class="chip chip-on" data-group="archived" data-value="">Todos</span>
                        <span class="chip" data-group="archived" data-value="0">Activos</span>
                        <span class="chip" data-group="archived" data-value="1">Archivados</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Buscador --}}
        <div class="row" style="margin:0 0 14px;gap:12px">
            <div style="position:relative;flex:1;max-width:460px">
                <span style="position:absolute;left:13px;top:50%;transform:translateY(-50%);opacity:.55">🔍</span>
                <input id="repo-search" placeholder="Buscar por nombre, descripción o tema…" autocomplete="off" style="padding-left:38px">
            </div>
            <span id="repo-count" class="muted tiny"></span>
        </div>

        {{-- Tabla --}}
        <div class="list-card">
            <table>
                <thead><tr>
                    <th>Repositorio</th><th>Documentación</th><th>Lenguaje</th>
                    <th>Visibilidad</th><th style="text-align:right">⭐</th><th>Actualizado</th><th></th>
                </tr></thead>
                <tbody>
                @foreach($repos as $r)
                    <tr class="repo-row"
                        data-owner="{{ strtolower($r->owner) }}"
                        data-lang="{{ strtolower($r->language) }}"
                        data-visibility="{{ $r->visibility }}"
                        data-archived="{{ $r->archived ? 1 : 0 }}"
                        data-search="{{ strtolower($r->full_name.' '.$r->description.' '.$r->notes.' '.$r->language.' '.implode(' ', $r->topics ?? [])) }}">
                        <td style="min-width:180px">
                            <a href="{{ $r->html_url }}" target="_blank" rel="noopener" style="font-weight:700;color:var(--text)">{{ $r->name }}</a>
                            @if($r->archived)<span class="tag" style="background:#3a2f14;color:#f7c76b;margin-left:6px">archivado</span>@endif
                            <div class="muted tiny" style="font-family:ui-monospace,monospace">{{ $r->owner }}</div>
                            @if($r->topics)<div style="margin-top:5px">@foreach(array_slice($r->topics,0,4) as $t)<span class="topic">{{ $t }}</span>@endforeach</div>@endif
                        </td>
                        <td style="max-width:360px">
                            @if($r->description)<div class="repo-desc">{{ $r->description }}</div>@endif
                            @if($r->notes)<div class="repo-note">📝 {{ $r->notes }}</div>@endif
                            @if(! $r->description && ! $r->notes)<span class="muted tiny">Sin descripción — documéntalo →</span>@endif
                            <a onclick="document.getElementById('note-{{ $r->id }}').classList.toggle('hidden')" class="tiny" style="color:var(--accent);cursor:pointer;display:inline-block;margin-top:5px">✏️ documentar</a>
                            <form id="note-{{ $r->id }}" class="hidden" method="POST" action="{{ route('dashboard.repositories.note', $r) }}" style="margin-top:6px">
                                @csrf @method('PUT')
                                <textarea name="notes" rows="2" placeholder="¿Para qué sirve este proyecto?" style="font-size:13px">{{ $r->notes }}</textarea>
                                <button class="btn btn-primary btn-sm" style="margin-top:5px">Guardar documentación</button>
                            </form>
                        </td>
                        <td>@if($r->language)<span class="pill tiny">{{ $r->language }}</span>@else<span class="muted tiny">—</span>@endif</td>
                        <td><span class="vis-badge vis-{{ $r->visibility }}">{{ $r->visibility === 'private' ? 'Privado' : 'Público' }}</span></td>
                        <td style="text-align:right;font-weight:600">{{ $r->stars }}</td>
                        <td class="muted tiny">{{ $r->pushed_at ? $r->pushed_at->diffForHumans() : '—' }}</td>
                        <td style="text-align:right"><a href="{{ $r->html_url }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">GitHub ↗</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif

@push('scripts')
<script>
    (function(){
        const state = {owner:'', lang:'', vis:'', archived:'', q:''};
        const searchInput = document.getElementById('repo-search');
        const count = document.getElementById('repo-count');
        function apply(){
            let shown = 0;
            document.querySelectorAll('.repo-row').forEach(r => {
                const ok = (!state.owner || r.dataset.owner === state.owner)
                    && (!state.lang || r.dataset.lang === state.lang)
                    && (!state.vis || r.dataset.visibility === state.vis)
                    && (!state.archived || r.dataset.archived === state.archived)
                    && (!state.q || (r.dataset.search || '').includes(state.q));
                r.classList.toggle('hidden', !ok);
                if (ok) shown++;
            });
            if (count) count.textContent = shown + ' repositorio' + (shown === 1 ? '' : 's');
        }
        document.querySelectorAll('.chip').forEach(c => c.addEventListener('click', () => {
            const g = c.dataset.group;
            state[g] = c.dataset.value;
            document.querySelectorAll('.chip[data-group="' + g + '"]').forEach(x => x.classList.toggle('chip-on', x === c));
            apply();
        }));
        searchInput?.addEventListener('input', () => { state.q = searchInput.value.trim().toLowerCase(); apply(); });
        apply();
    })();
</script>
@endpush
@endsection
