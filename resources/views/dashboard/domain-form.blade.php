@extends('dashboard.layout')
@section('title', 'Editar dominio · '.config('dashboard.title'))

@section('actions')
    <a href="{{ route('dashboard.domains') }}" class="btn btn-ghost btn-sm">← Dominios</a>
@endsection

@section('content')
<div style="max-width:640px;margin:0 auto">
    <h1 style="margin-bottom:18px">Editar dominio</h1>

    @if($errors->any())<div class="alert alert-bad">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('dashboard.domains.update', $domain) }}">
        @csrf @method('PUT')
        <div class="card">
            <div class="field"><label>Dominio</label><input name="name" value="{{ old('name',$domain->name) }}" required></div>
            <div class="form-grid">
                <div class="field"><label>Registrador</label>
                    <select name="registrar">
                        @foreach(['godaddy'=>'GoDaddy','ionos'=>'IONOS','winhosting'=>'Winhosting','otro'=>'Otro','desconocido'=>'Desconocido'] as $v=>$l)
                            <option value="{{ $v }}" @selected(old('registrar',$domain->registrar)===$v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field"><label>Vence el</label><input name="expires_at" type="date" value="{{ old('expires_at', optional($domain->expires_at)->format('Y-m-d')) }}"></div>
            </div>
            <div class="field"><label>Enlace de renovación</label><input name="renewal_url" type="url" value="{{ old('renewal_url',$domain->renewal_url) }}" placeholder="https://…"></div>
            <div class="field"><label>Notas</label><textarea name="notes" rows="2">{{ old('notes',$domain->notes) }}</textarea></div>
            <div class="row" style="justify-content:flex-end">
                <button class="btn btn-primary">Guardar cambios</button>
            </div>
        </div>
    </form>

    <form method="POST" action="{{ route('dashboard.domains.destroy', $domain) }}" style="margin-top:14px"
          onsubmit="return confirm('¿Eliminar este dominio del panel?')">
        @csrf @method('DELETE')
        <button class="btn btn-danger btn-sm">🗑️ Eliminar del panel</button>
    </form>
</div>
@endsection
