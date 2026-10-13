{{-- Self-contained styles: works on hosts without a Vite build (cPanel). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? config('marketing.description') }}">
    <meta property="og:title" content="{{ $title ?? config('app.name') }}">
    <meta property="og:description" content="{{ $description ?? config('marketing.description') }}">
    <meta property="og:type" content="website">
    <link rel="canonical" href="{{ url()->current() }}">
    <style>
        :root {
            --brand: #4f46e5; --brand-dark: #4338ca; --text: #18181b; --muted: #52525b; --line: #e4e4e7;
            --bg: #ffffff; --soft: #f4f4f5; --card: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root { --brand: #818cf8; --brand-dark: #6366f1; --text: #f4f4f5; --muted: #a1a1aa; --line: #27272a; --bg: #09090b; --soft: #18181b; --card: #18181b; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--text); background: var(--bg); line-height: 1.6; }
        a { color: var(--brand); }
        .wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }
        header.site { border-bottom: 1px solid var(--line); }
        header.site .wrap { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 64px; }
        .logo { font-weight: 700; font-size: 18px; color: var(--text); text-decoration: none; }
        nav.main { display: flex; align-items: center; gap: 20px; font-size: 15px; }
        nav.main a { color: var(--muted); text-decoration: none; }
        nav.main a:hover { color: var(--text); }
        .btn { display: inline-block; padding: 10px 18px; border-radius: 8px; font-weight: 600; text-decoration: none; border: 1px solid transparent; }
        .btn-primary { background: var(--brand); color: #fff !important; }
        .btn-primary:hover { background: var(--brand-dark); }
        .btn-ghost { border-color: var(--line); color: var(--text) !important; }
        .hero { padding: 88px 0 72px; text-align: center; }
        .hero h1 { font-size: clamp(34px, 6vw, 56px); line-height: 1.1; margin: 0 0 16px; letter-spacing: -0.02em; }
        .hero p { font-size: 19px; color: var(--muted); max-width: 680px; margin: 0 auto 32px; }
        .hero .actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        section { padding: 64px 0; }
        section.soft { background: var(--soft); }
        h2 { font-size: 30px; margin: 0 0 8px; letter-spacing: -0.01em; }
        .lead { color: var(--muted); margin: 0 0 32px; }
        .grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
        .card h3 { margin: 0 0 8px; font-size: 17px; }
        .card p { margin: 0; color: var(--muted); font-size: 15px; }
        .steps { counter-reset: step; }
        .steps .card h3::before { counter-increment: step; content: counter(step); display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 999px; background: var(--brand); color: #fff; font-size: 14px; margin-right: 10px; }
        .price { font-size: 34px; font-weight: 700; margin: 8px 0 0; }
        .price small { font-size: 15px; font-weight: 400; color: var(--muted); }
        .card ul { padding-left: 18px; color: var(--muted); font-size: 15px; }
        .prose { max-width: 760px; }
        .prose h1 { font-size: 34px; line-height: 1.2; }
        .prose h2 { font-size: 22px; margin-top: 32px; }
        .prose code { background: var(--soft); padding: 2px 6px; border-radius: 4px; font-size: 14px; }
        .prose pre { background: var(--soft); padding: 16px; border-radius: 8px; overflow-x: auto; }
        footer.site { border-top: 1px solid var(--line); padding: 32px 0; color: var(--muted); font-size: 14px; }
        footer.site .wrap { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        footer.site a { color: var(--muted); margin-left: 16px; }
        @media (max-width: 640px) { nav.main .hide-sm { display: none; } }
    </style>
</head>
<body>
    <header class="site">
        <div class="wrap">
            <a class="logo" href="{{ route('home') }}">{{ config('app.name') }}</a>
            <nav class="main" aria-label="Main">
                <a class="hide-sm" href="{{ route('home') }}#features">Features</a>
                <a class="hide-sm" href="{{ route('pricing') }}">Pricing</a>
                <a class="hide-sm" href="{{ route('help.index') }}">Help</a>
                <a href="{{ route('filament.app.auth.login') }}">Sign in</a>
                <a class="btn btn-primary" href="{{ route('filament.app.auth.register') }}">Start free</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="site">
        <div class="wrap">
            <div>&copy; {{ now()->year }} {{ config('marketing.company') }}</div>
            <div>
                <a href="{{ route('pricing') }}">Pricing</a>
                <a href="{{ route('help.index') }}">Help center</a>
                <a href="{{ route('legal.terms') }}">Terms</a>
                <a href="{{ route('legal.privacy') }}">Privacy</a>
                @if (filled(config('marketing.contact_email')))
                    <a href="mailto:{{ config('marketing.contact_email') }}">Contact</a>
                @endif
            </div>
        </div>
    </footer>
</body>
</html>
