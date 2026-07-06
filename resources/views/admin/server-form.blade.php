@extends('admin.layout')
@section('title', $server->exists ? 'Editar servidor' : 'Añadir servidor')

@section('content')
    <div class="toolbar">
        <a href="{{ route('admin.dashboard') }}" class="muted">← Servidores</a>
    </div>

    <h1>{{ $server->exists ? 'Editar servidor' : 'Añadir servidor' }}</h1>
    <p class="sub">Los datos y la contraseña se guardan cifrados en la base de datos.</p>

    @if ($errors->any())
        <div class="alert error">
            @foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach
        </div>
    @endif

    <form class="panel form-narrow" method="POST"
          action="{{ $server->exists ? route('admin.servers.update', $server) : route('admin.servers.store') }}">
        @csrf
        @if ($server->exists) @method('PUT') @endif

        <label>Nombre</label>
        <input name="name" value="{{ old('name', $server->name) }}" placeholder="VPS Producción" required>

        <div class="two-col">
            <div>
                <label>Grupo</label>
                <select name="group">
                    @foreach (['vps' => 'VPS Linux', 'winhosting' => 'WinHosting', 'otros' => 'Otros'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('group', $server->group) === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Identificador (opcional)</label>
                <input name="key" value="{{ old('key', $server->key) }}" placeholder="se genera del nombre">
            </div>
        </div>

        <div class="two-col">
            <div>
                <label>Host / IP</label>
                <input name="host" value="{{ old('host', $server->host) }}" placeholder="123.45.67.89" required>
            </div>
            <div>
                <label>Puerto</label>
                <input name="port" type="number" value="{{ old('port', $server->port ?? 22) }}" required>
            </div>
        </div>

        <label>Usuario SSH</label>
        <input name="username" value="{{ old('username', $server->username ?? 'root') }}" required>

        <label>Método de autenticación</label>
        <select name="auth_method" id="auth_method" onchange="toggleAuth()">
            <option value="key"      @selected(old('auth_method', $server->auth_method) === 'key')>Llave privada SSH</option>
            <option value="password" @selected(old('auth_method', $server->auth_method) === 'password')>Usuario + contraseña</option>
        </select>

        <div id="auth-key">
            <label>Ruta de la llave privada (en este servidor)</label>
            <input name="private_key_path" value="{{ old('private_key_path', $server->private_key_path) }}" placeholder="/home/forge/.ssh/id_rsa">
            <div class="field-hint">Ruta al archivo de la llave privada accesible por esta aplicación Laravel.</div>
        </div>

        <div id="auth-pass">
            <label>Contraseña SSH</label>
            <input name="password" type="password" placeholder="{{ $server->exists ? 'Dejar vacío para no cambiar' : '' }}" autocomplete="new-password">
            <div class="field-hint">Se almacena cifrada. En método por llave, puede usarse como passphrase de la llave.</div>
        </div>

        <label>Carpeta raíz a listar (opcional)</label>
        <input name="base_path" value="{{ old('base_path', $server->base_path) }}" placeholder="/var/www">

        <label>Notas (opcional)</label>
        <textarea name="notes" rows="2">{{ old('notes', $server->notes) }}</textarea>

        <div class="row" style="margin-top:22px;">
            <button type="submit" class="btn">{{ $server->exists ? 'Guardar cambios' : 'Añadir servidor' }}</button>
            <div class="spacer"></div>
            @if ($server->exists)
                <button form="delete-form" class="btn danger" onclick="return confirm('¿Eliminar este servidor?')">Eliminar</button>
            @endif
        </div>
    </form>

    @if ($server->exists)
        <form id="delete-form" method="POST" action="{{ route('admin.servers.destroy', $server) }}">
            @csrf @method('DELETE')
        </form>
    @endif

    <script>
        function toggleAuth() {
            const m = document.getElementById('auth_method').value;
            document.getElementById('auth-key').style.display  = (m === 'key') ? '' : 'none';
        }
        toggleAuth();
    </script>
@endsection
