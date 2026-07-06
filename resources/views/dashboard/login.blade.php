@extends('dashboard.layout')
@section('title', 'Ingresar · '.config('dashboard.title'))

@section('content')
<div style="max-width:400px;margin:8vh auto 0">
    <div class="card">
        <div style="text-align:center;margin-bottom:20px">
            <div class="logo" style="width:52px;height:52px;border-radius:14px;display:inline-grid;place-items:center;
                background:linear-gradient(145deg,#6366f1,#8b5cf6);font-size:26px;margin-bottom:10px">🖥️</div>
            <h1>{{ config('dashboard.title') }}</h1>
            <p class="muted tiny" style="margin-top:6px">Ingresa la contraseña del panel para continuar</p>
        </div>

        @if($errors->any())
            <div class="alert alert-bad">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('dashboard.login.attempt') }}">
            @csrf
            <div class="field">
                <label>Contraseña del panel</label>
                <input type="password" name="password" autofocus required placeholder="••••••••">
            </div>
            <button class="btn btn-primary" style="width:100%;justify-content:center">Entrar</button>
        </form>
    </div>
</div>
@endsection
