<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') · Expadu</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f6f5f1;
            --fg: #1c1916;
            --muted: #6b6860;
            --primary: #1a4cd4;
            --surface: #ffffff;
            --border: rgba(0, 0, 0, 0.08);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f0e0c;
                --fg: #f6f5f1;
                --muted: #9b9890;
                --surface: #1c1916;
                --border: rgba(255, 255, 255, 0.08);
            }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--fg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            max-width: 32rem;
            width: 100%;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }
        .code {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary);
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-top: 0.75rem;
            line-height: 1.2;
        }
        p {
            color: var(--muted);
            margin-top: 0.75rem;
            line-height: 1.5;
        }
        .actions {
            margin-top: 1.75rem;
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        a.btn {
            display: inline-block;
            padding: 0.625rem 1.25rem;
            border-radius: 0.625rem;
            background: var(--primary);
            color: white;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
        }
        a.btn-secondary {
            background: transparent;
            color: var(--fg);
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <main class="card" role="alert">
        @yield('content')
    </main>
</body>
</html>
