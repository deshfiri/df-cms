<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $appName = \App\Models\Setting::get('app_name', 'DFCP COMS');
        $themeHex = ltrim(\App\Models\Setting::get('theme_color', '#1F3C88'), '#');
        $themeColor = '#' . $themeHex;
        $tR = hexdec(substr($themeHex, 0, 2));
        $tG = hexdec(substr($themeHex, 2, 2));
        $tB = hexdec(substr($themeHex, 4, 2));
    @endphp
    <title>Unauthorized — {{ $appName }}</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function () {
            var t = localStorage.getItem('dfcp_theme');
            if (t === 'dark' || (t === null && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary: {{ $themeColor }};
            --primary-rgb: {{ $tR }}, {{ $tG }}, {{ $tB }};
            --bg: #f1f5f9;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, .13);
            --radius: 10px;
            --c-red: #dc2626;
            --c-red-bg: rgba(239, 68, 68, .1);
        }

        [data-theme="dark"] {
            --bg: #0B1220;
            --surface: #111827;
            --border: #1f2d40;
            --text: #F8FAFC;
            --text2: #CBD5E1;
            --text3: #94A3B8;
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, .6);
            --c-red: #EF4444;
            --c-red-bg: rgba(239, 68, 68, .15);
            color-scheme: dark;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .err-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            max-width: 420px;
            width: 90%;
            padding: 2.25rem 2rem;
            text-align: center;
        }

        .err-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: var(--c-red-bg);
            color: var(--c-red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            margin: 0 auto 1.25rem;
        }

        .err-card h1 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: .5rem;
        }

        .err-card p {
            font-size: .85rem;
            color: var(--text2);
            margin-bottom: 1.5rem;
        }

        .err-btn {
            background: var(--primary);
            border: none;
            color: #fff;
            font-size: .85rem;
            font-weight: 500;
            padding: .55rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
        }

        .err-btn:hover { opacity: .9; color: #fff; }
    </style>
</head>

<body>
    <div class="err-card">
        <div class="err-icon"><i class="bi bi-shield-lock"></i></div>
        <h1>Unauthorized</h1>
        <p>{{ $exception->getMessage() ?: "You don't have permission to do this." }}</p>
        <a href="{{ url('/') }}" class="err-btn">Back to Dashboard</a>
    </div>

    <script>
        Swal.fire({
            icon: 'error',
            title: 'Unauthorized',
            text: @json($exception->getMessage() ?: "You don't have permission to do this."),
        });
    </script>
</body>

</html>
