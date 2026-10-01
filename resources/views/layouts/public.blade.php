<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    @php
        $appName = \App\Models\Setting::get('app_name', 'DFCP COMS');
        $branding = app(\App\Services\Storage\BrandingAssetService::class);
        $appLogo = $branding->url('app_logo');
        $appFavicon = $branding->url('app_favicon');
        $themeHex = ltrim(\App\Models\Setting::get('theme_color', '#1F3C88'), '#');
        $themeColor = '#' . $themeHex;
    @endphp
    <title>@yield('title', 'Home') — {{ $appName }}</title>

    @if($appFavicon)
        <link rel="icon" href="{{ $appFavicon }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script>
        (function () {
            var t = localStorage.getItem('dfcp_theme');
            if (t === 'dark' || (t === null && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/bootstrap-icons.css') }}">

    <style>
        :root {
            --primary: {{ $themeColor }};
            --bg: #f1f5f9;
            --surface: #ffffff;
            --surface2: #f8fafc;
            --border: #e2e8f0;
            --text: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;
            --radius: 10px;
            --shadow-sm: 0 1px 2px rgba(15, 23, 42, .06);
            --shadow-md: 0 4px 16px rgba(15, 23, 42, .08);
        }

        [data-theme="dark"] {
            --bg: #0B1220;
            --surface: #111827;
            --surface2: #1a2235;
            --border: #1f2d40;
            --text: #F8FAFC;
            --text2: #CBD5E1;
            --text3: #94A3B8;
            color-scheme: dark;
        }

        body { background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif; }

        .pub-nav { background: var(--surface); border-bottom: 1px solid var(--border); }
        .pub-nav a.brand-link { color: var(--text); text-decoration: none; font-weight: 700; }
        .pub-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); transition: box-shadow .15s; }
        .pub-card:hover { box-shadow: var(--shadow-md); }
        .pub-logo { width: 56px; height: 56px; border-radius: var(--radius); object-fit: cover; background: var(--surface2); }
        .pub-muted { color: var(--text3); }
    </style>

    @stack('styles')
</head>

<body>
    <nav class="pub-nav py-3 mb-4">
        <div class="container d-flex align-items-center justify-content-between">
            <a href="{{ route('landing') }}" class="brand-link d-flex align-items-center gap-2">
                @if($appLogo)
                    <img src="{{ $appLogo }}" alt="{{ $appName }}" style="height:28px">
                @endif
                <span>{{ $appName }}</span>
            </a>
            <a href="{{ route('login') }}" class="btn btn-sm btn-outline-secondary">Sign in</a>
        </div>
    </nav>

    <main class="container pb-5">
        @yield('content')
    </main>

    @stack('scripts')
</body>

</html>
