@php
    // ── Navegación: detecta la sección activa a partir de la ruta ────────────
    $isServers = request()->routeIs('dashboard.index') || request()->routeIs('dashboard.servers.*');
    $isDomains = request()->routeIs('dashboard.domains') || request()->routeIs('dashboard.domains.*');
    $isConfig  = request()->routeIs('dashboard.config') || request()->routeIs('dashboard.alerts')
                 || request()->routeIs('dashboard.alerts.*');
    $bare = request()->routeIs('dashboard.login');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0b1020">
    <title>@yield('title', config('dashboard.title'))</title>
    <style>
        :root{
            --bg:#080c18; --bg2:#0f1730; --card:#141d38; --card2:#1a2547;
            --line:#26324f; --line2:#2f3d63; --text:#e9eefb; --muted:#96a3c6;
            --muted2:#5f6c8f; --accent:#6d6cf7; --accent2:#8b5cf6; --cyan:#38e1d6;
            --ok:#2fd67a; --warn:#f7b23b; --bad:#f5566e;
            --radius:16px; --sb-w:246px; --sb-mini:74px; --tb-h:62px;
            --glass:rgba(20,29,56,.72);
        }
        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{
            background:
              radial-gradient(1100px 620px at 12% -8%, #1b2452 0%, transparent 55%),
              radial-gradient(900px 560px at 110% 6%, #201a4d 0%, transparent 50%),
              var(--bg);
            color:var(--text);font-family:'Segoe UI',system-ui,-apple-system,Roboto,Helvetica,Arial,sans-serif;
            min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}
        a{color:inherit;text-decoration:none}

        /* ══ Componentes base (compatibles con todas las vistas) ══ */
        .btn{display:inline-flex;align-items:center;gap:8px;padding:9px 15px;border-radius:11px;
            border:1px solid var(--line);background:var(--card2);color:var(--text);cursor:pointer;
            font-size:14px;font-weight:600;transition:.15s;white-space:nowrap}
        .btn:hover{border-color:var(--accent);background:#212e58;transform:translateY(-1px)}
        .btn-primary{background:linear-gradient(145deg,var(--accent),var(--accent2));border-color:transparent;
            box-shadow:0 6px 18px rgba(109,108,247,.32)}
        .btn-primary:hover{filter:brightness(1.08);box-shadow:0 8px 22px rgba(109,108,247,.45)}
        .btn-ghost{background:transparent}
        .btn-danger{border-color:#4a2230;color:#fca5a5}
        .btn-danger:hover{background:#3a1a26;border-color:var(--bad)}
        .btn-sm{padding:7px 12px;font-size:13px}
        .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
        .grid{display:grid;gap:18px}
        .cards{grid-template-columns:repeat(auto-fill,minmax(310px,1fr))}
        .card{background:linear-gradient(180deg,var(--card),var(--bg2));border:1px solid var(--line);
            border-radius:var(--radius);padding:18px;transition:.18s;position:relative}
        .card.link:hover{border-color:var(--accent);transform:translateY(-3px);
            box-shadow:0 16px 40px rgba(0,0,0,.4),0 0 0 1px rgba(109,108,247,.25)}
        .muted{color:var(--muted)}
        .tiny{font-size:12px}
        .hidden{display:none}
        .pill{display:inline-flex;align-items:center;gap:6px;padding:3px 9px;border-radius:999px;
            font-size:12px;font-weight:600;border:1px solid var(--line);background:#0e1630}
        .dot{width:8px;height:8px;border-radius:50%;background:var(--muted)}
        .dot.ok{background:var(--ok);box-shadow:0 0 8px var(--ok)}
        .dot.bad{background:var(--bad);box-shadow:0 0 8px var(--bad)}
        .accent-bar{height:4px;border-radius:6px;margin:-18px -18px 16px;border-radius:16px 16px 0 0}
        .meter{background:#0b1327;border:1px solid var(--line);border-radius:9px;height:9px;overflow:hidden}
        .meter > span{display:block;height:100%;border-radius:9px;transition:width .5s ease}
        .metric-label{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px}
        .stat{background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:13px 14px;transition:.15s}
        .stat:hover{border-color:var(--line2)}
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
            background:#0b1327;color:var(--text);font-size:14px;font-family:inherit}
        input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent);
            box-shadow:0 0 0 3px rgba(109,108,247,.16)}
        label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:600}
        .field{margin-bottom:16px}
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .alert{padding:12px 14px;border-radius:11px;margin-bottom:16px;font-size:14px;border:1px solid}
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

        /* ══ App shell: sidebar + main ══ */
        .app{display:flex;min-height:100vh}
        .sidebar{
            position:fixed;top:0;left:0;bottom:0;width:var(--sb-w);z-index:60;
            background:linear-gradient(180deg,rgba(17,24,50,.96),rgba(9,13,26,.98));
            border-right:1px solid var(--line);backdrop-filter:blur(14px);
            display:flex;flex-direction:column;transition:width .22s cubic-bezier(.4,0,.2,1),transform .25s ease}
        .sb-head{display:flex;align-items:center;gap:11px;padding:16px 16px 14px;min-height:var(--tb-h)}
        .sb-logo{width:40px;height:40px;border-radius:12px;flex:0 0 auto;display:grid;place-items:center;
            background:linear-gradient(145deg,var(--accent),var(--accent2));font-size:19px;font-weight:800;color:#fff;
            box-shadow:0 8px 22px rgba(109,108,247,.45);position:relative;overflow:hidden;cursor:pointer;transition:transform .15s}
        .sb-logo:hover{transform:scale(1.06)}
        .sb-logo::after{content:"";position:absolute;inset:0;background:radial-gradient(circle at 30% 20%,rgba(255,255,255,.5),transparent 60%)}
        .sb-brand{display:flex;flex-direction:column;line-height:1.15;overflow:hidden;white-space:nowrap}
        .sb-brand b{font-size:19px;letter-spacing:2px;font-weight:800;
            background:linear-gradient(90deg,#fff,#c9c8ff);-webkit-background-clip:text;background-clip:text;color:transparent}
        .sb-brand small{font-size:10.5px;color:var(--muted);letter-spacing:.6px;text-transform:uppercase}
        .sb-collapse{margin-left:auto;width:26px;height:26px;border-radius:8px;border:1px solid var(--line);
            background:var(--card2);color:var(--muted);cursor:pointer;display:grid;place-items:center;flex:0 0 auto;transition:.15s}
        .sb-collapse:hover{color:var(--text);border-color:var(--accent)}
        .sb-nav{flex:1;overflow-y:auto;overflow-x:hidden;padding:8px 12px;display:flex;flex-direction:column;gap:3px}
        .sb-sec{font-size:10.5px;letter-spacing:.9px;text-transform:uppercase;color:var(--muted2);
            padding:14px 12px 5px;font-weight:700;white-space:nowrap}
        .sb-link{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:11px;color:var(--muted);
            font-weight:600;font-size:14.5px;position:relative;transition:.14s;white-space:nowrap}
        .sb-link .ic{width:22px;text-align:center;font-size:17px;flex:0 0 auto}
        .sb-link .lbl{overflow:hidden;text-overflow:ellipsis}
        .sb-link:hover{background:var(--card2);color:var(--text)}
        .sb-link.on{color:#fff;background:linear-gradient(90deg,rgba(109,108,247,.22),rgba(139,92,246,.06))}
        .sb-link.on::before{content:"";position:absolute;left:-12px;top:8px;bottom:8px;width:4px;border-radius:0 4px 4px 0;
            background:linear-gradient(180deg,var(--accent),var(--cyan));box-shadow:0 0 12px var(--accent)}
        .sb-foot{padding:12px;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:8px}
        .sb-user{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:11px;background:var(--card2);
            border:1px solid var(--line);white-space:nowrap;overflow:hidden}
        .sb-user .av{width:30px;height:30px;border-radius:9px;flex:0 0 auto;display:grid;place-items:center;
            background:linear-gradient(145deg,#243056,#1a2340);font-size:15px}
        .sb-user .who{overflow:hidden;line-height:1.2}
        .sb-user .who b{font-size:13px;font-weight:700;display:block;overflow:hidden;text-overflow:ellipsis}
        .sb-user .who small{font-size:11px;color:var(--muted)}

        /* Colapsado (mini-rail de escritorio) */
        .app.mini .sidebar{width:var(--sb-mini)}
        .app.mini .sb-brand,.app.mini .sb-sec,.app.mini .sb-link .lbl,
        .app.mini .sb-user .who,.app.mini .sb-collapse{display:none}
        .app.mini .sb-head{justify-content:center;padding:16px 0 14px}
        .app.mini .sb-link{justify-content:center;padding:11px 0}
        .app.mini .sb-nav{padding:8px}
        .app.mini .sb-user{justify-content:center;padding:8px}
        .app.mini .sb-link.on::before{left:-8px}
        .app.mini .main{margin-left:var(--sb-mini)}
        .app.mini .sb-link:hover::after{content:attr(data-tip);position:absolute;left:calc(100% + 10px);top:50%;
            transform:translateY(-50%);background:#0b1327;border:1px solid var(--line2);color:var(--text);
            padding:6px 10px;border-radius:8px;font-size:13px;white-space:nowrap;z-index:80;
            box-shadow:0 8px 20px rgba(0,0,0,.5)}

        .main{flex:1;margin-left:var(--sb-w);min-width:0;transition:margin .22s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}
        .topbar{position:sticky;top:0;z-index:40;height:var(--tb-h);display:flex;align-items:center;gap:12px;
            padding:0 22px;background:var(--glass);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
        .tb-burger{display:none;width:38px;height:38px;border-radius:10px;border:1px solid var(--line);
            background:var(--card2);color:var(--text);cursor:pointer;place-items:center;font-size:18px;flex:0 0 auto}
        .tb-title{display:flex;flex-direction:column;line-height:1.2;min-width:0}
        .tb-title b{font-size:15px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tb-title small{font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .tb-actions{margin-left:auto;display:flex;gap:9px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
        .content{padding:22px;max-width:1240px;width:100%;margin:0 auto}

        .backdrop{display:none;position:fixed;inset:0;background:rgba(4,7,15,.6);backdrop-filter:blur(2px);z-index:55}

        /* ══ Móvil: sidebar off-canvas ══ */
        @media(max-width:900px){
            .sidebar{transform:translateX(-100%);width:var(--sb-w);box-shadow:0 0 60px rgba(0,0,0,.6)}
            .app.open .sidebar{transform:translateX(0)}
            .app.open .backdrop{display:block}
            .main{margin-left:0}
            .app.mini .main{margin-left:0}
            .tb-burger{display:grid}
            .content{padding:16px}
            .form-grid{grid-template-columns:1fr}
            .fb{grid-template-columns:1fr}
            .sb-collapse{display:none}
            /* Topbar apilado: acciones en su propia fila con scroll horizontal */
            .topbar{height:auto;min-height:var(--tb-h);flex-wrap:wrap;padding:10px 16px;gap:10px}
            .tb-title small{display:none}
            .tb-actions{order:3;flex-basis:100%;flex-wrap:nowrap;overflow-x:auto;
                justify-content:flex-start;margin-left:0;padding-bottom:2px;scrollbar-width:none}
            .tb-actions::-webkit-scrollbar{display:none}
            .tb-actions > *{flex:0 0 auto}
        }

        /* ══ Login (sin shell) ══ */
        .bare-wrap{max-width:1100px;margin:0 auto;padding:22px 20px 60px}
    </style>
</head>
<body>
@if($bare)
    {{-- Pantalla de login: sin barra lateral --}}
    <div class="bare-wrap">
        @if(session('status'))<div class="alert alert-ok">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-bad">{{ session('error') }}</div>@endif
        @yield('content')
    </div>
@else
    <div class="app" id="app">
        <aside class="sidebar">
            <div class="sb-head">
                <div class="sb-logo" id="sbLogo" title="Expandir / colapsar menú">◆</div>
                <div class="sb-brand">
                    <b>{{ config('dashboard.brand') }}</b>
                    <small>Centro de control</small>
                </div>
                <button class="sb-collapse" id="sbCollapse" title="Colapsar menú" aria-label="Colapsar menú">‹</button>
            </div>

            <nav class="sb-nav">
                <div class="sb-sec">Infraestructura</div>
                <a href="{{ route('dashboard.index') }}" class="sb-link {{ $isServers ? 'on' : '' }}" data-tip="Servidores">
                    <span class="ic">🖥️</span><span class="lbl">Servidores</span>
                </a>
                <a href="{{ route('dashboard.domains') }}" class="sb-link {{ $isDomains ? 'on' : '' }}" data-tip="Dominios">
                    <span class="ic">🌐</span><span class="lbl">Dominios</span>
                </a>

                <div class="sb-sec">Sistema</div>
                <a href="{{ route('dashboard.config') }}" class="sb-link {{ $isConfig ? 'on' : '' }}" data-tip="Configuración">
                    <span class="ic">⚙️</span><span class="lbl">Configuración</span>
                </a>
            </nav>

            <div class="sb-foot">
                <div class="sb-user">
                    <span class="av">🛰️</span>
                    <span class="who"><b>{{ config('dashboard.title') }}</b><small>Panel operativo</small></span>
                </div>
                @if(config('dashboard.password'))
                    <form method="POST" action="{{ route('dashboard.logout') }}">
                        @csrf
                        <button class="btn btn-ghost btn-sm" style="width:100%;justify-content:center">↩ Salir</button>
                    </form>
                @endif
            </div>
        </aside>

        <div class="backdrop" id="backdrop"></div>

        <div class="main">
            <header class="topbar">
                <button class="tb-burger" id="tbBurger" aria-label="Abrir menú">☰</button>
                <div class="tb-title">
                    <b>@yield('title', config('dashboard.title'))</b>
                    <small>@yield('subtitle', 'todos tus servidores en un solo lugar')</small>
                </div>
                <div class="tb-actions">
                    @yield('actions')
                </div>
            </header>

            <div class="content">
                @if(session('status'))<div class="alert alert-ok">{{ session('status') }}</div>@endif
                @if(session('error'))<div class="alert alert-bad">{{ session('error') }}</div>@endif
                @yield('content')
            </div>
        </div>
    </div>

    <script>
        (function(){
            const app = document.getElementById('app');
            const burger = document.getElementById('tbBurger');
            const backdrop = document.getElementById('backdrop');
            const collapse = document.getElementById('sbCollapse');
            const logo = document.getElementById('sbLogo');

            // Estado colapsado (mini-rail) persistente en escritorio
            if(localStorage.getItem('nexo-mini') === '1') app.classList.add('mini');
            const toggleMini = () => {
                app.classList.toggle('mini');
                localStorage.setItem('nexo-mini', app.classList.contains('mini') ? '1' : '0');
            };
            collapse && collapse.addEventListener('click', toggleMini);
            // El logo también expande/colapsa (imprescindible para reabrir en modo compacto)
            logo && logo.addEventListener('click', toggleMini);

            // Drawer móvil
            const openM = () => app.classList.add('open');
            const closeM = () => app.classList.remove('open');
            burger && burger.addEventListener('click', openM);
            backdrop && backdrop.addEventListener('click', closeM);
            document.querySelectorAll('.sb-link').forEach(a => a.addEventListener('click', () => {
                if(window.innerWidth <= 900) closeM();
            }));
        })();
    </script>
@endif
    @stack('scripts')
</body>
</html>
