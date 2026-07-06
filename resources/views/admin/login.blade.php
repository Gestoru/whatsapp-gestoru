<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso · Panel de Servidores</title>
    <style>
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            background:#0f1420; color:#e6ecf5; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
        .box { width:360px; background:#182031; border:1px solid #2a3852; border-radius:14px; padding:30px; }
        .brand { font-weight:700; font-size:18px; margin-bottom:4px; display:flex; align-items:center; gap:10px; }
        .brand .dot { width:10px;height:10px;border-radius:50%;background:#6ee7b7;box-shadow:0 0 10px #6ee7b7; }
        p.sub { color:#8ea0bd; margin:0 0 22px; font-size:13px; }
        label { display:block; font-weight:600; margin-bottom:6px; font-size:13px; }
        input { width:100%; background:#0f1420; border:1px solid #2a3852; color:#e6ecf5; padding:11px 12px;
            border-radius:8px; font-size:14px; box-sizing:border-box; }
        input:focus { outline:none; border-color:#4f9dff; }
        button { width:100%; margin-top:18px; background:#4f9dff; color:#041226; border:none; padding:11px;
            border-radius:8px; font-weight:700; cursor:pointer; font-size:15px; }
        .err { background:#2a1620; border:1px solid #6e2b3b; color:#ffb3c0; padding:10px 12px; border-radius:8px;
            margin-bottom:16px; font-size:13px; }
        .warn { background:#2a2416; border:1px solid #6e5b2b; color:#ffe3a0; padding:10px 12px; border-radius:8px;
            margin-bottom:16px; font-size:13px; }
        code { background:#0f1420; padding:2px 6px; border-radius:4px; font-size:12px; }
    </style>
</head>
<body>
    <form class="box" method="POST" action="{{ route('admin.login.post') }}">
        @csrf
        <div class="brand"><span class="dot"></span> Gestoru · Servidores</div>
        <p class="sub">Panel de administración por SSH</p>

        @if ($errors->any())
            <div class="err">{{ $errors->first() }}</div>
        @endif

        @if ($notConfigured)
            <div class="warn">Configura <code>SERVER_ADMIN_PASSWORD</code> en tu archivo <code>.env</code> para activar el acceso.</div>
        @endif

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" autofocus autocomplete="current-password">
        <button type="submit">Entrar</button>
    </form>
</body>
</html>
