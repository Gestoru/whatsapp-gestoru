<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel de Servidores') · Gestoru</title>
    <style>
        :root {
            --bg: #0f1420; --panel: #182031; --panel-2: #1f2a3d; --border: #2a3852;
            --text: #e6ecf5; --muted: #8ea0bd; --accent: #4f9dff; --accent-2: #6ee7b7;
            --danger: #ff6b6b; --warn: #ffd166; --ok: #3ddc97;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg); color: var(--text); font-size: 14px; line-height: 1.5;
        }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
        header.top {
            background: linear-gradient(180deg, #131a29, #0f1420);
            border-bottom: 1px solid var(--border); padding: 14px 0; position: sticky; top: 0; z-index: 10;
        }
        header.top .wrap { display: flex; align-items: center; justify-content: space-between; }
        .brand { font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 10px; }
        .brand .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent-2); box-shadow: 0 0 10px var(--accent-2); }
        .nav a { color: var(--muted); margin-left: 18px; font-weight: 500; }
        .nav a:hover { color: var(--text); text-decoration: none; }
        main { padding: 28px 0 60px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 14px; color: var(--text); }
        .sub { color: var(--muted); margin: 0 0 24px; }
        .grid { display: grid; gap: 16px; }
        .cards { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
        .metrics { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .panel {
            background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 18px;
        }
        .card {
            background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 18px;
            transition: border-color .15s, transform .15s; display: block;
        }
        .card:hover { border-color: var(--accent); transform: translateY(-2px); text-decoration: none; }
        .card .name { font-size: 16px; font-weight: 600; color: var(--text); }
        .card .host { color: var(--muted); font-family: ui-monospace, Menlo, monospace; font-size: 13px; margin-top: 2px; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .tag {
            display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px;
            background: var(--panel-2); color: var(--muted); border: 1px solid var(--border); text-transform: uppercase; letter-spacing: .04em;
        }
        .tag.vps { color: #9fd0ff; border-color: #315579; }
        .tag.winhosting { color: #ffd6a0; border-color: #7a5a2e; }
        .muted { color: var(--muted); }
        .mono { font-family: ui-monospace, Menlo, monospace; }
        .metric-label { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .05em; }
        .metric-value { font-size: 26px; font-weight: 700; margin: 4px 0 8px; }
        .bar { height: 8px; background: var(--panel-2); border-radius: 6px; overflow: hidden; }
        .bar > span { display: block; height: 100%; border-radius: 6px; background: linear-gradient(90deg, var(--ok), var(--accent)); }
        .bar.warn > span { background: linear-gradient(90deg, var(--warn), #ff9f43); }
        .bar.danger > span { background: linear-gradient(90deg, #ff9f43, var(--danger)); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 9px 10px; border-bottom: 1px solid var(--border); }
        th { color: var(--muted); font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        td.mono { font-size: 13px; }
        tr:last-child td { border-bottom: none; }
        .btn {
            display: inline-flex; align-items: center; gap: 7px; background: var(--accent); color: #041226;
            border: none; padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;
        }
        .btn:hover { filter: brightness(1.08); text-decoration: none; }
        .btn.ghost { background: transparent; color: var(--text); border: 1px solid var(--border); }
        .btn.danger { background: var(--danger); color: #2a0808; }
        .btn.sm { padding: 6px 12px; font-size: 13px; }
        input, select, textarea {
            width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text);
            padding: 10px 12px; border-radius: 8px; font-size: 14px; font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); }
        label { display: block; font-weight: 600; margin: 14px 0 6px; font-size: 13px; }
        .field-hint { color: var(--muted); font-size: 12px; margin-top: 4px; font-weight: 400; }
        .alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; border: 1px solid; }
        .alert.error { background: #2a1620; border-color: #6e2b3b; color: #ffb3c0; }
        .alert.ok { background: #142a22; border-color: #2e6e52; color: #a0f0c9; }
        .alert.info { background: #16233a; border-color: #34517d; color: #bcd6ff; }
        .status-dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block; margin-right: 6px; }
        .status-dot.on { background: var(--ok); box-shadow: 0 0 8px var(--ok); }
        .status-dot.off { background: var(--muted); }
        .status-dot.err { background: var(--danger); }
        .toolbar { display: flex; gap: 10px; align-items: center; margin-bottom: 22px; flex-wrap: wrap; }
        .spacer { flex: 1; }
        .breadcrumb { font-family: ui-monospace, Menlo, monospace; font-size: 13px; margin-bottom: 16px; word-break: break-all; }
        .breadcrumb a { color: var(--accent); }
        .empty { text-align: center; padding: 50px 20px; color: var(--muted); }
        .form-narrow { max-width: 640px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 640px) { .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="top">
        <div class="wrap">
            <div class="brand"><span class="dot"></span> Gestoru · Servidores</div>
            <nav class="nav">
                <a href="{{ route('admin.dashboard') }}">Servidores</a>
                <a href="{{ route('admin.servers.create') }}">Añadir</a>
                <form action="{{ route('admin.logout') }}" method="POST" style="display:inline; margin-left:18px;">
                    @csrf
                    <button type="submit" style="background:none;border:none;color:var(--muted);cursor:pointer;font-weight:500;">Salir</button>
                </form>
            </nav>
        </div>
    </header>
    <main>
        <div class="wrap">
            @if (session('status'))
                <div class="alert ok">{{ session('status') }}</div>
            @endif
            @yield('content')
        </div>
    </main>
</body>
</html>
