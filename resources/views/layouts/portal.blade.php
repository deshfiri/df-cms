<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $appName = \App\Models\Setting::get('app_name', 'DFCP COMS');
        $themeHex = ltrim(\App\Models\Setting::get('theme_color', '#1F3C88'), '#');
        $themeColor = '#' . $themeHex;
        $tR = hexdec(substr($themeHex, 0, 2));
        $tG = hexdec(substr($themeHex, 2, 2));
        $tB = hexdec(substr($themeHex, 4, 2));
        $themeColorDark = sprintf('#%02x%02x%02x', max(0, (int) round($tR * .82)), max(0, (int) round($tG * .82)), max(0, (int) round($tB * .82)));
        $shade = fn($f) => sprintf('#%02x%02x%02x', (int) round($tR * $f), (int) round($tG * $f), (int) round($tB * $f));
        $sbBgTop = $shade(.30);
        $sbBgBottom = $shade(.16);
        $sbBgTopDarkMode = $shade(.20);
        $sbBgBottomDarkMode = $shade(.10);
        $portalUser = auth('client_portal')->user();
        $client = $portalUser->client;
    @endphp
    <title>@yield('title', 'Dashboard') — {{ $appName }} Client Portal</title>

    @php $appFavicon = app(\App\Services\Storage\BrandingAssetService::class)->url('app_favicon'); @endphp
    @if($appFavicon)
        <link rel="icon" href="{{ $appFavicon }}">
        <link rel="apple-touch-icon" href="{{ $appFavicon }}">
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        :root {
            --primary: {{ $themeColor }};
            --primary-dark: {{ $themeColorDark }};
            --primary-rgb: {{ $tR }}, {{ $tG }}, {{ $tB }};

            --sidebar-w: 260px;
            --topbar-h: 58px;

            --bg: #f1f5f9;
            --surface: #ffffff;
            --surface2: #f8fafc;
            --border: #e2e8f0;
            --text: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;

            --sb-bg-top: {{ $sbBgTop }};
            --sb-bg-bottom: {{ $sbBgBottom }};
            --sb-text: rgba(255, 255, 255, .55);
            --sb-hover: rgba(255, 255, 255, .07);
            --sb-active: rgba(255, 255, 255, .1);
            --sb-bd: rgba(255, 255, 255, .07);

            --shadow-sm: 0 1px 2px rgba(0, 0, 0, .04), 0 1px 8px rgba(0, 0, 0, .03);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .07);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, .13);
            --radius: 10px;

            --c-green: #059669; --c-green-bg: rgba(16, 185, 129, .1);
            --c-yellow: #d97706; --c-yellow-bg: rgba(245, 158, 11, .1);
            --c-red: #dc2626; --c-red-bg: rgba(239, 68, 68, .1);
            --c-blue: #2563eb; --c-blue-bg: rgba(59, 130, 246, .1);
        }

        [data-theme="dark"] {
            --bg: #0B1220;
            --surface: #111827;
            --surface2: #1a2235;
            --border: #1f2d40;
            --text: #F8FAFC;
            --text2: #CBD5E1;
            --text3: #94A3B8;
            --sb-bg-top: {{ $sbBgTopDarkMode }};
            --sb-bg-bottom: {{ $sbBgBottomDarkMode }};
            --c-green: #22C55E; --c-green-bg: rgba(34, 197, 94, .15);
            --c-yellow: #F59E0B; --c-yellow-bg: rgba(245, 158, 11, .15);
            --c-red: #EF4444; --c-red-bg: rgba(239, 68, 68, .15);
            --c-blue: #3B82F6; --c-blue-bg: rgba(59, 130, 246, .15);
            color-scheme: dark;
        }

        * { box-sizing: border-box; }
        html { font-size: 14px; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }

        #sidebar {
            position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
            background: linear-gradient(180deg, var(--sb-bg-top), var(--sb-bg-bottom));
            display: flex; flex-direction: column; z-index: 1040; overflow-y: auto;
        }
        .sb-brand {
            padding: 0 18px; height: var(--topbar-h); display: flex; align-items: center; gap: 10px;
            border-bottom: 1px solid var(--sb-bd); flex-shrink: 0;
        }
        .sb-brand-icon {
            width: 32px; height: 32px; border-radius: 8px; background: rgba(var(--primary-rgb), .28);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1rem; flex-shrink: 0;
        }
        .sb-brand-name { font-size: .92rem; font-weight: 700; color: #fff; }
        .sb-brand-sub { font-size: .68rem; color: var(--sb-text); }
        .sb-nav { padding: 10px 8px; flex: 1; }
        .sb-link {
            display: flex; align-items: center; gap: 10px; padding: 9px 13px; border-radius: 7px;
            color: var(--sb-text); text-decoration: none; font-size: .87rem; font-weight: 500; margin-bottom: 2px;
        }
        .sb-link:hover { background: var(--sb-hover); color: rgba(255,255,255,.85); }
        .sb-link.active { background: var(--sb-active); color: #fff; box-shadow: inset 3px 0 0 var(--primary); }
        .sb-link.active i { color: var(--primary); }
        .sb-link i { font-size: 1.05rem; width: 20px; text-align: center; flex-shrink: 0; }

        #main { margin-left: var(--sidebar-w); min-height: 100vh; }
        #topbar {
            height: var(--topbar-h); background: var(--surface); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; padding: 0 20px;
            position: sticky; top: 0; z-index: 1030;
        }
        .topbar-title { font-size: .95rem; font-weight: 600; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .icon-btn {
            width: 34px; height: 34px; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border);
            color: var(--text2); display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .icon-btn:hover { color: var(--text); }
        #content { padding: 22px; }

        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
        .spill {
            display: inline-flex; align-items: center; gap: 5px; padding: 2px 9px 2px 7px; border-radius: 20px;
            font-size: .67rem; font-weight: 600; white-space: nowrap;
        }
        .spill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; background: currentColor; opacity: .7; }
        .spill-green { background: var(--c-green-bg); color: var(--c-green); }
        .spill-yellow { background: var(--c-yellow-bg); color: var(--c-yellow); }
        .spill-red { background: var(--c-red-bg); color: var(--c-red); }
        .spill-blue { background: var(--c-blue-bg); color: var(--c-blue); }
        .spill-gray { background: var(--surface2); color: var(--text3); }

        @media (max-width: 900px) {
            #sidebar { transform: translateX(-100%); transition: transform .2s; }
            #sidebar.open { transform: translateX(0); }
            #main { margin-left: 0; }
        }
    </style>
    @stack('styles')
</head>
<body>

<aside id="sidebar">
    <div class="sb-brand">
        <div class="sb-brand-icon"><i class="bi bi-shop"></i></div>
        <div>
            <div class="sb-brand-name">{{ $client->client_name }}</div>
            <div class="sb-brand-sub">{{ $client->dfid_number }}</div>
        </div>
    </div>
    <nav class="sb-nav">
        <a href="{{ route('portal.dashboard') }}" class="sb-link {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}"><i class="bi bi-speedometer2"></i>Dashboard</a>
        <a href="{{ route('portal.journey') }}" class="sb-link {{ request()->routeIs('portal.journey') ? 'active' : '' }}"><i class="bi bi-signpost-split"></i>My Journey</a>
        <a href="{{ route('portal.services.index') }}" class="sb-link {{ request()->routeIs('portal.services.*') ? 'active' : '' }}"><i class="bi bi-grid-1x2"></i>My Services</a>
        <a href="{{ route('portal.updates.index') }}" class="sb-link {{ request()->routeIs('portal.updates.*') ? 'active' : '' }}"><i class="bi bi-megaphone"></i>Project Updates</a>
        @if(Route::has('portal.actions.index'))
        <a href="{{ route('portal.actions.index') }}" class="sb-link {{ request()->routeIs('portal.actions.*') ? 'active' : '' }}"><i class="bi bi-list-check"></i>Pending Actions</a>
        @endif
        @if(Route::has('portal.approvals.index'))
        <a href="{{ route('portal.approvals.index') }}" class="sb-link {{ request()->routeIs('portal.approvals.*') ? 'active' : '' }}"><i class="bi bi-patch-check"></i>Approvals</a>
        @endif
        <a href="{{ route('portal.documents.index') }}" class="sb-link {{ request()->routeIs('portal.documents.*') ? 'active' : '' }}"><i class="bi bi-folder2-open"></i>Documents</a>
        <a href="{{ route('portal.invoices.index') }}" class="sb-link {{ request()->routeIs('portal.invoices.*') || request()->routeIs('portal.payments.*') ? 'active' : '' }}"><i class="bi bi-credit-card"></i>Payments</a>
        @if(Route::has('portal.support.index'))
        <a href="{{ route('portal.support.index') }}" class="sb-link {{ request()->routeIs('portal.support.*') ? 'active' : '' }}"><i class="bi bi-headset"></i>Support</a>
        @endif
        @if(Route::has('portal.information.index'))
        <a href="{{ route('portal.information.index') }}" class="sb-link {{ request()->routeIs('portal.information.*') || request()->routeIs('portal.correction-requests.*') ? 'active' : '' }}"><i class="bi bi-person-vcard"></i>My Information</a>
        @endif
        @if(Route::has('portal.notifications.index'))
        <a href="{{ route('portal.notifications.index') }}" class="sb-link {{ request()->routeIs('portal.notifications.*') ? 'active' : '' }}"><i class="bi bi-bell"></i>Notifications</a>
        @endif
        @if(Route::has('portal.profile.edit'))
        <a href="{{ route('portal.profile.edit') }}" class="sb-link {{ request()->routeIs('portal.profile.*') ? 'active' : '' }}"><i class="bi bi-gear"></i>Profile</a>
        @endif
        <form method="POST" action="{{ route('portal.logout') }}">
            @csrf
            <button type="submit" class="sb-link border-0 bg-transparent w-100 text-start"><i class="bi bi-box-arrow-left"></i>Logout</button>
        </form>
    </nav>
</aside>

<div id="main">
    <div id="topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="icon-btn d-md-none" id="sidebarToggle"><i class="bi bi-list"></i></button>
            <div class="topbar-title">@yield('title', 'Dashboard')</div>
        </div>
        <div class="topbar-actions">
            <button class="icon-btn" id="themeToggle"><i id="themeIcon" class="bi bi-moon-stars"></i></button>
            <span class="d-none d-sm-inline" style="font-size:.8rem;color:var(--text2)">{{ $portalUser->name }}</span>
        </div>
    </div>
    <div id="content">
        @yield('content')
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function updateThemeIcon() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.getElementById('themeIcon').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
}
updateThemeIcon();
document.getElementById('themeToggle').addEventListener('click', function () {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
    localStorage.setItem('dfcp_theme', dark ? 'light' : 'dark');
    updateThemeIcon();
});
document.getElementById('sidebarToggle')?.addEventListener('click', function () {
    document.getElementById('sidebar').classList.toggle('open');
});
$.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') } });
</script>
@stack('scripts')
</body>
</html>
