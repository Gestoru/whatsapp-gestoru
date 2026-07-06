@extends('admin.layout')
@section('title', 'Servidores')

@section('content')
    <div class="row" style="margin-bottom:6px;">
        <h1>Servidores</h1>
        <a href="{{ route('admin.servers.create') }}" class="btn">+ Añadir servidor</a>
    </div>
    <p class="sub">
        {{ $servers->count() }} servidor(es) · modo
        <span class="tag">{{ $mode === 'readonly' ? 'solo lectura' : $mode }}</span>
    </p>

    @if ($servers->isEmpty())
        <div class="panel empty">
            <p>Aún no hay servidores configurados.</p>
            <p class="muted">Añade uno desde el botón de arriba, o define <span class="mono">SERVER_1_HOST</span> en tu <span class="mono">.env</span>.</p>
            <a href="{{ route('admin.servers.create') }}" class="btn" style="margin-top:10px;">+ Añadir el primero</a>
        </div>
    @else
        <div class="grid cards">
            @foreach ($servers as $s)
                <div class="card">
                    <div class="row">
                        <div>
                            <div class="name">{{ $s['name'] }}</div>
                            <div class="host">{{ $s['username'] }}@{{ $s['host'] }}:{{ $s['port'] }}</div>
                        </div>
                        <span class="tag {{ $s['group'] }}">{{ $s['group'] }}</span>
                    </div>

                    <div class="row" style="margin-top:14px;">
                        <span class="muted" data-test="{{ $s['key'] }}">
                            <span class="status-dot off"></span> Sin verificar
                        </span>
                        <span class="tag">{{ $s['auth_method'] === 'key' ? 'llave SSH' : 'password' }}</span>
                    </div>

                    <div class="row" style="margin-top:16px; gap:8px;">
                        <a href="{{ route('admin.server.show', $s['key']) }}" class="btn sm">Métricas</a>
                        <a href="{{ route('admin.server.folders', $s['key']) }}" class="btn sm ghost">Carpetas</a>
                        <div class="spacer"></div>
                        @if (($s['source'] ?? null) === 'db')
                            <a href="{{ route('admin.servers.edit', $s['id']) }}" class="btn sm ghost">Editar</a>
                        @else
                            <span class="tag" title="Definido en .env">.env</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <script>
        // Prueba de conexión asíncrona por servidor
        const token = document.querySelector('meta[name="csrf-token"]').content;
        document.querySelectorAll('[data-test]').forEach(async (el) => {
            const key = el.getAttribute('data-test');
            try {
                const res = await fetch(`{{ url('admin/s') }}/${key}/test`, { headers: { 'X-CSRF-TOKEN': token } });
                const data = await res.json();
                if (data.ok) {
                    el.innerHTML = '<span class="status-dot on"></span> En línea · ' + (data.uptime || '');
                } else {
                    el.innerHTML = '<span class="status-dot err"></span> Error de conexión';
                    el.title = data.error || '';
                }
            } catch (e) {
                el.innerHTML = '<span class="status-dot err"></span> No disponible';
            }
        });
    </script>
@endsection
