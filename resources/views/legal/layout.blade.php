<!DOCTYPE html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} – Finans</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #1f2933;
            --muted: #52606d;
            --line: #e4e7eb;
            --accent: #2563eb;
            --bg: #f5f7fa;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap {
            max-width: 760px;
            margin: 0 auto;
            padding: 48px 24px 96px;
        }
        header.page {
            border-bottom: 1px solid var(--line);
            padding-bottom: 20px;
            margin-bottom: 32px;
        }
        header.page .eyebrow {
            text-transform: uppercase;
            letter-spacing: .08em;
            font-size: 12px;
            font-weight: 600;
            color: var(--accent);
            margin: 0 0 6px;
        }
        h1 {
            font-size: 30px;
            line-height: 1.2;
            margin: 0 0 8px;
        }
        .updated {
            color: var(--muted);
            font-size: 14px;
            margin: 0;
        }
        h2 {
            font-size: 19px;
            margin: 36px 0 10px;
        }
        p, li { color: var(--ink); }
        ul { padding-left: 22px; }
        li { margin: 4px 0; }
        a { color: var(--accent); }
        .lead { font-size: 17px; color: var(--muted); }
        footer.page {
            margin-top: 56px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            font-size: 14px;
            color: var(--muted);
        }
        footer.page a { margin-right: 16px; }
    </style>
</head>
<body>
    <div class="wrap">
        <header class="page">
            <p class="eyebrow">Finans</p>
            <h1>{{ $title }}</h1>
            <p class="updated">Sist oppdatert {{ $updated }}</p>
        </header>

        <main>
            @yield('content')
        </main>

        <footer class="page">
            <a href="/privacy">Personvern</a>
            <a href="/terms">Vilkår</a>
            @if (config('legal.operator_email'))
                <span>Kontakt: <a href="mailto:{{ config('legal.operator_email') }}">{{ config('legal.operator_email') }}</a></span>
            @endif
        </footer>
    </div>
</body>
</html>
