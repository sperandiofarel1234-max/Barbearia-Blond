<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Barbearia')</title>
    <style>
        :root { --bg:#f6f5f2; --card:#fff; --ink:#1d1d1f; --muted:#6b6b70; --brand:#1f4d3a; --brand-ink:#fff; --line:#e2e0da; --danger:#a12d2d; --ok:#1d6b3a; --warn:#8a5a00; }
        @media (prefers-color-scheme: dark) { :root { --bg:#161716; --card:#202221; --ink:#eceae4; --muted:#a1a19a; --brand:#4f9d79; --brand-ink:#0b1a13; --line:#34373a; --danger:#e07a7a; --ok:#6fcf97; --warn:#e0b252; } }
        * { box-sizing: border-box; }
        body { margin:0; font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--ink); }
        header { background:var(--card); border-bottom:1px solid var(--line); }
        .wrap { max-width:960px; margin:0 auto; padding:0 16px; }
        nav { display:flex; flex-wrap:wrap; gap:4px 16px; align-items:center; padding:12px 0; }
        nav .brand { font-weight:700; margin-right:auto; color:var(--ink); text-decoration:none; }
        nav a, nav button.link { color:var(--ink); text-decoration:none; background:none; border:0; font:inherit; cursor:pointer; padding:4px 0; }
        nav a:hover, nav button.link:hover { text-decoration:underline; }
        main { padding:24px 0 48px; }
        h1 { font-size:1.6rem; margin:0 0 16px; } h2 { font-size:1.2rem; margin:24px 0 8px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
        .grid { display:grid; gap:16px; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); }
        .muted { color:var(--muted); } .right { text-align:right; }
        label { display:block; font-weight:600; margin:12px 0 4px; }
        input[type=text],input[type=email],input[type=tel],input[type=date],input[type=number],input[type=password],textarea { width:100%; padding:10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--ink); font:inherit; }
        .btn { display:inline-block; padding:10px 16px; border-radius:8px; border:1px solid var(--brand); background:var(--brand); color:var(--brand-ink); font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
        .btn.secondary { background:transparent; color:var(--ink); border-color:var(--line); }
        .btn.danger { background:var(--danger); border-color:var(--danger); color:#fff; }
        .btn.small { padding:5px 10px; font-size:.9rem; }
        .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; border:1px solid; }
        .alert.success { border-color:var(--ok); color:var(--ok); } .alert.error { border-color:var(--danger); color:var(--danger); }
        .field-error { color:var(--danger); font-size:.9rem; margin:4px 0 0; }
        table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:8px; border-bottom:1px solid var(--line); vertical-align:top; } th { font-size:.85rem; color:var(--muted); }
        .table-scroll { overflow-x:auto; }
        .badge { display:inline-block; padding:2px 8px; border-radius:99px; font-size:.8rem; font-weight:600; border:1px solid currentColor; }
        .badge.confirmed { color:var(--warn); } .badge.completed { color:var(--ok); } .badge.cancelled { color:var(--danger); }
        .slots { display:flex; flex-wrap:wrap; gap:8px; margin:8px 0; }
        .slots label { margin:0; font-weight:500; }
        .slots input { position:absolute; opacity:0; }
        .slots span { display:inline-block; padding:8px 14px; border:1px solid var(--line); border-radius:8px; cursor:pointer; }
        .slots input:checked + span { background:var(--brand); color:var(--brand-ink); border-color:var(--brand); }
        .slots input:focus-visible + span { outline:2px solid var(--brand); outline-offset:2px; }
        .stats { display:grid; gap:12px; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); }
        .stat b { display:block; font-size:1.4rem; }
        form.inline { display:inline; }
    </style>
</head>
<body>
<header>
    <div class="wrap">
        <nav aria-label="Navegação principal">
            <a class="brand" href="{{ auth()->user()?->is_admin ? route('admin.dashboard') : route('home') }}">✂ Barbearia</a>
            @if (auth()->user()?->is_admin)
                <a href="{{ route('admin.dashboard') }}">Painel</a>
                <a href="{{ route('admin.agenda') }}">Agenda</a>
                <a href="{{ route('admin.services.index') }}">Serviços</a>
                <a href="{{ route('admin.report') }}">Faturamento</a>
                <form class="inline" method="POST" action="{{ route('logout') }}">@csrf<button class="link" type="submit">Sair</button></form>
            @else
                <a href="{{ route('home') }}">Agendar</a>
                <a href="{{ route('login') }}">Área do barbeiro</a>
            @endif
        </nav>
    </div>
</header>
<main>
    <div class="wrap">
        @if (session('success'))<div class="alert success" role="status">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="alert error" role="alert">{{ session('error') }}</div>@endif
        @yield('content')
    </div>
</main>
</body>
</html>
