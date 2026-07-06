@extends('admin.layout')
@section('title', 'Carpetas · ' . $server['name'])

@section('content')
    <div class="toolbar">
        <a href="{{ route('admin.dashboard') }}" class="muted">← Servidores</a>
        <a href="{{ route('admin.server.show', $server['key']) }}" class="muted">Métricas</a>
        <div class="spacer"></div>
        <span class="tag {{ $server['group'] }}">{{ $server['name'] }}</span>
    </div>

    <h1>Explorador de carpetas</h1>
    <p class="sub mono">{{ $server['username'] }}@{{ $server['host'] }}</p>

    {{-- Breadcrumb --}}
    <div class="breadcrumb">
        <a href="{{ route('admin.server.folders', [$server['key'], 'path' => '/']) }}">/</a>
        @foreach ($crumbs as $c)
            <a href="{{ route('admin.server.folders', [$server['key'], 'path' => $c['path']]) }}">{{ $c['name'] }}</a>{{ !$loop->last ? ' / ' : '' }}
        @endforeach
    </div>

    @if ($error)
        <div class="alert error"><strong>Error de conexión:</strong> {{ $error }}</div>
    @elseif ($result['error'])
        <div class="alert error">{{ $result['error'] }}</div>
    @else
        <div class="panel">
            <table>
                <thead>
                    <tr><th style="width:56px;">Tipo</th><th>Nombre</th><th>Tamaño</th><th>Permisos</th><th>Propietario</th><th>Modificado</th></tr>
                </thead>
                <tbody>
                    @if ($parent !== null)
                        <tr>
                            <td>📁</td>
                            <td><a href="{{ route('admin.server.folders', [$server['key'], 'path' => $parent]) }}">..</a></td>
                            <td colspan="4" class="muted">carpeta superior</td>
                        </tr>
                    @endif
                    @forelse ($result['entries'] as $e)
                        <tr>
                            <td>{{ $e['type'] === 'dir' ? '📁' : ($e['type'] === 'link' ? '🔗' : '📄') }}</td>
                            <td class="mono">
                                @if ($e['type'] === 'dir')
                                    <a href="{{ route('admin.server.folders', [$server['key'], 'path' => rtrim($result['path'], '/') . '/' . $e['name']]) }}">{{ $e['name'] }}</a>
                                @else
                                    {{ $e['name'] }}
                                @endif
                            </td>
                            <td>{{ $e['type'] === 'dir' ? '—' : $e['size'] }}</td>
                            <td class="mono muted">{{ $e['perms'] }}</td>
                            <td class="mono muted">{{ $e['owner'] }}:{{ $e['group'] }}</td>
                            <td class="muted">{{ $e['modified'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">Carpeta vacía.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="muted" style="margin-top:12px; font-size:12px;">
            {{ count($result['entries']) }} elemento(s) en <span class="mono">{{ $result['path'] }}</span>
        </p>
    @endif
@endsection
