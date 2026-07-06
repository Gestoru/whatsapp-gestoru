<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('dashboard.title'))</title>
    <style>
        :root{
            --bg:#0b1020; --bg2:#111830; --card:#151d36; --card2:#1b2544;
            --line:#26324f; --text:#e7ecf6; --muted:#94a3c4;
            --muted2:#64748b; --accent:#6366f1; --ok:#22c55e; --warn:#f59e0b; --bad:#ef4444;
            --radius:14px;
        }
        *{box-sizing:border-box}
        body{margin:0;background:radial-gradient(1200px 600px at 20% -10%,#182147 0%,var(--bg) 55%);
            color:var(--text);font-family:'Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
            min-height:100vh;-webkit-font-smoothing:antialiased}
        a{color:inherit;text-decoration:none}
        .wrap{max-width:1200px;margin:0 auto;padding:22px 20px 60px}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:26px;flex-wrap:wrap}
        .brand{display:flex;align-items:center;gap:12px;font-size:20px;font-weight:700;letter-spacing:.2px}
        .brand .logo{width:38px;height:38px;border-radius:11px;display:grid;place-items:center;
            background:linear-gradient(145deg,#6366f1,#8b5cf6);font-size:20px;box-shadow:0 6px 20px rgba(99,102,241,.35)}
        .brand small{display:block;font-size:12px;color:var(--muted);font-weight:500}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:9px 15px;border-radius:10px;
            border:1px solid var(--line);background:var(--card2);color:var(--text);cursor:pointer;
            font-size:14px;font-weight:600;transition:.15s;white-space:nowrap}
        .btn:hover{border-color:var(--accent);background:#202c52}
        .btn-primary{background:linear-gradient(145deg,#6366f1,#7c3aed);border-color:transparent}
        .btn-primary:hover{filter:brightness(1.08);background:linear-gradient(145deg,#6366f1,#7c3aed)}
        .btn-ghost{background:transparent}
        .btn-danger{border-color:#4a2230;color:#fca5a5}
        .btn-danger:hover{background:#3a1a26;border-color:var(--bad)}
        .btn-sm{padding:6px 11px;font-size:13px}
        .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .grid{display:grid;gap:18px}
        .cards{grid-template-columns:repeat(auto-fill,minmax(310px,1fr))}
        .card{background:linear-gradient(180deg,var(--card),var(--bg2));border:1px solid var(--line);
            border-radius:var(--radius);padding:18px;transition:.15s}
        .card.link:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 10px 30px rgba(0,0,0,.3)}
        .muted{color:var(--muted)}
        .tiny{font-size:12px}
        .pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;
            font-size:12px;font-weight:600;border:1px solid var(--line);background:#0e1630}
        .dot{width:8px;height:8px;border-radius:50%;background:var(--muted)}
        .dot.ok{background:var(--ok);box-shadow:0 0 8px var(--ok)}
        .dot.bad{background:var(--bad);box-shadow:0 0 8px var(--bad)}
        .accent-bar{height:4px;border-radius:6px;margin:-18px -18px 16px;border-radius:14px 14px 0 0}
        .meter{background:#0e1630;border:1px solid var(--line);border-radius:9px;height:9px;overflow:hidden}
        .meter > span{display:block;height:100%;border-radius:9px;transition:width .5s ease}
        .metric-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px}
        .stat{background:var(--card2);border:1px solid var(--line);border-radius:11px;padding:13px 14px}
        .stat .k{font-size:12px;color:var(--muted)}
        .stat .v{font-size:19px;font-weight:700;margin-top:3px}
        h1{font-size:22px;margin:0}
        h2{font-size:16px;margin:26px 0 12px;display:flex;align-items:center;gap:9px}
        .section-ic{width:26px;height:26px;border-radius:8px;background:var(--card2);display:grid;place-items:center;font-size:14px;border:1px solid var(--line)}
        table{width:100%;border-collapse:collapse;font-size:14px}
        th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line)}
        th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
        tr:last-child td{border-bottom:none}
        .list-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden}
        .tag{font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
        .tag-pm2{background:#1e3a2b;color:#6ee7a8} .tag-docker{background:#12324a;color:#67c7f0}
        .tag-servicio{background:#3a2f14;color:#f7c76b} .tag-carpeta{background:#2b2450;color:#b3a4f5}
        input,select,textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);
            background:#0e1630;color:var(--text);font-size:14px;font-family:inherit}
        input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
        label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:600}
        .field{margin-bottom:16px}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-size:14px;border:1px solid}
        .alert-ok{background:#0f2a1a;border-color:#1e5637;color:#86efac}
        .alert-bad{background:#2a1116;border-color:#5b2330;color:#fca5a5}
        .empty{text-align:center;padding:50px 20px;color:var(--muted)}
        .empty .big{font-size:44px;margin-bottom:10px}
        .fb{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:16px}
        .fb-pane{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;min-height:340px}
        .fb-head{padding:11px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:8px;
            background:var(--card2);font-size:13px;font-family:ui-monospace,monospace;overflow-x:auto;white-space:nowrap}
        .fb-list{max-height:440px;overflow:auto}
        .fb-item{display:flex;align-items:center;gap:10px;padding:8px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid #1a2340}
        .fb-item:hover{background:var(--card2)}
        .fb-item .ic{width:18px;text-align:center}
        .fb-item .meta{margin-left:auto;color:var(--muted);font-size:12px;font-family:ui-monospace,monospace}
        .fb-file{padding:0}
        .fb-file pre{margin:0;padding:14px;max-height:440px;overflow:auto;font-family:ui-monospace,'Cascadia Code',monospace;
            font-size:13px;line-height:1.5;white-space:pre;color:#d7def0}
        .crumb{cursor:pointer;color:var(--muted)} .crumb:hover{color:var(--accent)}
        .spin{display:inline-block;width:16px;height:16px;border:2px solid var(--line);border-top-color:var(--accent);
            border-radius:50%;animation:sp .7s linear infinite}
        @keyframes sp{to{transform:rotate(360deg)}}
        @media(max-width:820px){.fb{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <a href="{{ route('dashboard.index') }}" class="brand">
                <span class="logo">🖥️</span>
                <span>{{ config('dashboard.title') }}<small>Panel de servidores · @yield('subtitle','todos en un solo lugar')</small></span>
            </a>
            <div class="row">
                @yield('actions')
                @if(config('dashboard.password'))
                    <form method="POST" action="{{ route('dashboard.logout') }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm">Salir</button>
                    </form>
                @endif
            </div>
        </div>

        @if(session('status'))
            <div class="alert alert-ok">{{ session('status') }}</div>
        @endif

        @yield('content')
    </div>
    @stack('scripts')
</body>
</html>
