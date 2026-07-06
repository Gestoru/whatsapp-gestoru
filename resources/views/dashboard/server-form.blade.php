@extends('dashboard.layout')
@php($editing = $server->exists)
@section('title', ($editing?'Editar':'Agregar').' servidor · '.config('dashboard.title'))

@section('actions')
    <a href="{{ $editing ? route('dashboard.servers.show',$server) : route('dashboard.index') }}" class="btn btn-ghost btn-sm">← Volver</a>
@endsection

@section('content')
<div style="max-width:760px;margin:0 auto">
    <h1 style="margin-bottom:18px">{{ $editing ? 'Editar servidor' : 'Agregar servidor' }}</h1>

    @if($errors->any())
        <div class="alert alert-bad">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ $editing ? route('dashboard.servers.update',$server) : route('dashboard.servers.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <div class="form-grid">
                <div class="field">
                    <label>Nombre <span class="muted">(cómo lo identificas)</span></label>
                    <input name="name" value="{{ old('name',$server->name) }}" placeholder="Servidor principal Contabo" required>
                </div>
                <div class="field">
                    <label>Proveedor</label>
                    <select name="provider">
                        @foreach(['contabo'=>'Contabo','winhosting'=>'Winhosting','otro'=>'Otro'] as $v=>$l)
                            <option value="{{ $v }}" @selected(old('provider',$server->provider)===$v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>IP o dominio (host)</label>
                    <input name="host" value="{{ old('host',$server->host) }}" placeholder="123.45.67.89" required>
                </div>
                <div class="field">
                    <label>Puerto SSH</label>
                    <input name="port" type="number" value="{{ old('port',$server->port ?: 22) }}" required>
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Usuario</label>
                    <input name="username" value="{{ old('username',$server->username ?: 'root') }}" placeholder="root" required>
                </div>
                <div class="field">
                    <label>Método de acceso</label>
                    <select name="auth_type" id="auth_type">
                        <option value="password" @selected(old('auth_type',$server->auth_type)!=='key')>Contraseña</option>
                        <option value="key" @selected(old('auth_type',$server->auth_type)==='key')>Llave SSH privada</option>
                    </select>
                </div>
            </div>

            <div class="field auth-password">
                <label>Contraseña SSH @if($editing && $server->hasCredentials())<span class="muted">(dejar vacío para no cambiar)</span>@endif</label>
                <input name="password" type="password" autocomplete="new-password" placeholder="••••••••"
                       @if($editing && ! $server->hasCredentials()) autofocus @endif>
            </div>

            <div class="auth-key" style="display:none">
                <div class="field">
                    <label>Llave privada (contenido de tu archivo id_rsa / id_ed25519) @if($editing)<span class="muted">(vacío = no cambiar)</span>@endif</label>
                    <textarea name="private_key" rows="6" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" style="font-family:ui-monospace,monospace;font-size:12px"></textarea>
                </div>
                <div class="field">
                    <label>Passphrase de la llave <span class="muted">(opcional)</span></label>
                    <input name="key_passphrase" type="password" autocomplete="new-password">
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label>Color del acento</label>
                    <input name="color" type="color" value="{{ old('color',$server->color ?: '#6366f1') }}" style="height:44px;padding:4px">
                </div>
                <div class="field" style="display:flex;align-items:flex-end;gap:8px;padding-bottom:10px">
                    <input type="checkbox" name="is_active" value="1" id="is_active" style="width:auto" @checked(old('is_active',$server->is_active ?? true))>
                    <label for="is_active" style="margin:0">Activo</label>
                </div>
            </div>

            <div class="field">
                <label>Notas <span class="muted">(opcional)</span></label>
                <textarea name="notes" rows="2" placeholder="Ej: aquí corre WhatsApp + la API">{{ old('notes',$server->notes) }}</textarea>
            </div>

            <h2 style="margin:6px 0 12px;font-size:15px">💳 Plan y pago <span class="muted tiny" style="font-weight:400">(opcional)</span></h2>
            <div class="form-grid">
                <div class="field">
                    <label>Plan vigente hasta / próxima fecha de pago</label>
                    <input name="paid_until" type="date" value="{{ old('paid_until', optional($server->paid_until)->format('Y-m-d')) }}">
                </div>
                <div class="field">
                    <label>Costo mensual (USD)</label>
                    <input name="monthly_cost" type="number" step="0.01" min="0" value="{{ old('monthly_cost',$server->monthly_cost) }}" placeholder="18.00">
                </div>
            </div>
            <div class="field">
                <label>Enlace para pagar/renovar <span class="muted">(si lo dejas vacío usa el del proveedor)</span></label>
                <input name="renewal_url" type="url" value="{{ old('renewal_url',$server->renewal_url) }}" placeholder="https://my.contabo.com/invoices">
            </div>

            <div class="alert" style="background:#101a33;border-color:var(--line);color:var(--muted)">
                🔒 Las credenciales se guardan <strong style="color:var(--text)">cifradas</strong> en la base de datos y nunca se muestran de vuelta.
            </div>

            <div class="row" style="justify-content:flex-end">
                <button class="btn btn-primary">{{ $editing ? 'Guardar cambios' : 'Agregar servidor' }}</button>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
const sel=document.getElementById('auth_type');
function toggleAuth(){
    const key = sel.value==='key';
    document.querySelector('.auth-key').style.display = key?'block':'none';
    document.querySelector('.auth-password').style.display = key?'none':'block';
}
sel.addEventListener('change',toggleAuth); toggleAuth();
</script>
@endpush
