<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $appName = \App\Models\Setting::get('app_name', 'DFCP COMS');
        $appLogo = \App\Models\Setting::get('app_logo');
        $appFavicon = \App\Models\Setting::get('app_favicon');
        $themeHex = ltrim(\App\Models\Setting::get('theme_color', '#1F3C88'), '#');
        $themeColor = '#' . $themeHex;
        $tR = hexdec(substr($themeHex, 0, 2));
        $tG = hexdec(substr($themeHex, 2, 2));
        $tB = hexdec(substr($themeHex, 4, 2));
        $themeColorDark = sprintf(
            '#%02x%02x%02x',
            max(0, (int) round($tR * .82)),
            max(0, (int) round($tG * .82)),
            max(0, (int) round($tB * .82))
        );
        // Sidebar is a permanently dark surface, so we shade the theme color
        // toward black (scaling every channel down keeps hue/saturation intact)
        // rather than using it at full brightness — keeps white nav text readable
        // no matter how light a theme color is picked.
        $shade = fn($f) => sprintf(
            '#%02x%02x%02x',
            (int) round($tR * $f),
            (int) round($tG * $f),
            (int) round($tB * $f)
        );
        $sbBgTop = $shade(.30);
        $sbBgBottom = $shade(.16);
        $sbBgTopDarkMode = $shade(.20);
        $sbBgBottomDarkMode = $shade(.10);
    @endphp
    <title>@yield('title', 'Dashboard') — {{ $appName }}</title>

    {{-- Uploaded in Settings. The filename carries an upload timestamp, so a
         replacement gets a fresh URL instead of the cached old icon. --}}
    @if($appFavicon)
        <link rel="icon" href="{{ asset($appFavicon) }}">
        <link rel="apple-touch-icon" href="{{ asset($appFavicon) }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- No-FOUC dark mode init --}}
    <script>
        (function () {
            var t = localStorage.getItem('dfcp_theme');
            if (t === 'dark' || (t === null && window.matchMedia('(prefers-color-scheme:dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>

    {{-- Vendored under public/vendor — same versions, same minified bytes, served
         from our own origin. This is an internal tool that runs on the office
         network: pulling the entire UI toolkit from five external CDNs made the
         interface depend on working internet, and cost a DNS + TLS handshake per
         host on every cold visit. See public/vendor/README.md. --}}
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/bootstrap-icons.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/dataTables.bootstrap5.min.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/sweetalert2.min.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/select2.min.css') }}">
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('vendor/css/select2-bootstrap-5-theme.min.css') }}">

    <style>
        /* ── Variables ─────────────────────────────────────────────── */
        :root {
            --primary:
                {{ $themeColor }}
            ;
            --primary-dark:
                {{ $themeColorDark }}
            ;
            --primary-rgb:
                {{ $tR }}
                ,
                {{ $tG }}
                ,
                {{ $tB }}
            ;

            --sidebar-w: 280px;
            --sidebar-mini: 66px;
            --topbar-h: 58px;

            --bg: #f1f5f9;
            --surface: #ffffff;
            --surface2: #f8fafc;
            --border: #e2e8f0;
            --text: #0f172a;
            --text2: #475569;
            --text3: #94a3b8;

            --sb-bg-top:
                {{ $sbBgTop }}
            ;
            --sb-bg-bottom:
                {{ $sbBgBottom }}
            ;
            --sb-text: rgba(255, 255, 255, .55);
            --sb-hover: rgba(255, 255, 255, .07);
            --sb-active: rgba(255, 255, 255, .1);
            --sb-bd: rgba(255, 255, 255, .07);

            --bs-primary:
                {{ $themeColor }}
            ;
            --bs-primary-rgb:
                {{ $tR }}
                ,
                {{ $tG }}
                ,
                {{ $tB }}
            ;
            --bs-link-color:
                {{ $themeColor }}
            ;
            --bs-link-hover-color:
                {{ $themeColorDark }}
            ;
            --bs-link-color-rgb:
                {{ $tR }}
                ,
                {{ $tG }}
                ,
                {{ $tB }}
            ;

            --shadow-sm: 0 1px 2px rgba(0, 0, 0, .04), 0 1px 8px rgba(0, 0, 0, .03);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, .07);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, .13);
            --t: .2s ease;
            --radius: 10px;

            --c-green: #059669;
            --c-green-bg: rgba(16, 185, 129, .1);
            --c-yellow: #d97706;
            --c-yellow-bg: rgba(245, 158, 11, .1);
            --c-red: #dc2626;
            --c-red-bg: rgba(239, 68, 68, .1);
            --c-blue: #2563eb;
            --c-blue-bg: rgba(59, 130, 246, .1);
            --c-purple: #7c3aed;
            --c-purple-bg: rgba(124, 58, 237, .1);
            --c-slate: #64748b;
            --c-slate-bg: rgba(100, 116, 139, .1);
            --c-rose: #e11d48;
            --c-rose-bg: rgba(225, 29, 72, .1);

            /* ── Secondary (neutral action color, distinct from primary) ─── */
            --secondary: #64748b;
            --secondary-rgb: 100, 116, 139;
            --secondary-dark: #475569;
            --secondary-bg: rgba(100, 116, 139, .1);

            /* ── Type scale ──────────────────────────────────────────────── */
            --fs-h1: 1.75rem;
            --fs-h2: 1.375rem;
            --fs-h3: 1.125rem;
            --fs-h4: 1rem;
            --fs-body: .875rem;
            --fs-sm: .8125rem;
            --fs-xs: .75rem;
            --fs-2xs: .6875rem;

            --fw-medium: 500;
            --fw-semibold: 600;
            --fw-bold: 700;

            /* ── Spacing — 4px grid ──────────────────────────────────────── */
            --space-1: 4px;
            --space-2: 8px;
            --space-3: 12px;
            --space-4: 16px;
            --space-5: 20px;
            --space-6: 24px;
            --space-7: 28px;
            --space-8: 32px;

            /* ── Radius tiers (--radius kept for backward compatibility) ─── */
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
        }

        [data-theme="dark"] {
            --secondary: #94a3b8;
            --secondary-rgb: 148, 163, 184;
            --secondary-dark: #cbd5e1;
            --secondary-bg: rgba(148, 163, 184, .15);
            /* ── Layout surfaces ─── */
            --bg: #0B1220;
            --surface: #111827;
            --surface2: #1a2235;
            --border: #1f2d40;
            color-scheme: dark;

            /* ── Typography ──────── */
            --text: #F8FAFC;
            --text2: #CBD5E1;
            --text3: #94A3B8;

            /* ── Shadows ─────────── */
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, .4);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, .5);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, .6);

            /* ── Semantic colors ─── */
            --c-green: #22C55E;
            --c-green-bg: rgba(34, 197, 94, .15);
            --c-yellow: #F59E0B;
            --c-yellow-bg: rgba(245, 158, 11, .15);
            --c-red: #EF4444;
            --c-red-bg: rgba(239, 68, 68, .15);
            --c-blue: #3B82F6;
            --c-blue-bg: rgba(59, 130, 246, .15);
            --c-purple: #A78BFA;
            --c-purple-bg: rgba(167, 139, 250, .15);
            --c-slate: #06B6D4;
            --c-slate-bg: rgba(6, 182, 212, .15);
            --c-rose: #F43F5E;
            --c-rose-bg: rgba(244, 63, 94, .15);

            --sb-bg-top:
                {{ $sbBgTopDarkMode }}
            ;
            --sb-bg-bottom:
                {{ $sbBgBottomDarkMode }}
            ;
        }
    </style>

    {{-- The rest of the shell's CSS is static, so it is a cacheable file rather
         than ~55 KB re-sent inside every page. Versioned by modified time. --}}
    <link rel="stylesheet" href="{{ App\Support\ShellAsset::url('css/shell.css') }}">

    @stack('styles')
</head>

<body>

    {{-- ── Sidebar ──────────────────────────────────────────────────── --}}
    <aside id="sidebar">

        <div class="sb-brand">
            @if($appLogo)
                <img src="{{ asset($appLogo) }}" alt="{{ $appName }}" class="sb-brand-logo">
            @else
                <div class="sb-brand-icon"><i class="bi bi-shop"></i></div>
                <div class="sb-brand-text">
                    <div class="sb-brand-name">{{ $appName }}</div>
                    <div class="sb-brand-sub">Client Management</div>
                </div>
            @endif
        </div>

        <nav class="sb-nav">
            {{-- Stage/department workers get a minimal, work-queue-only menu (see dashboard-department). --}}
            @php $isStageUser = auth()->user()->can('submit-stage') && !auth()->user()->hasRole(['Super Admin', 'Manager']); @endphp
            @php
                $flowNav = app(\App\Services\FlowService::class)->navSummary(auth()->user());
                $flowParticipant = $flowNav['participant'];
                $flowQueueCount = $flowNav['count'];
            @endphp
            <div class="sb-section">Menu</div>
            <a href="{{ route('dashboard') }}" class="sb-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                title="{{ $isStageUser ? 'My Work' : 'Dashboard' }}" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi {{ $isStageUser ? 'bi-clipboard-check' : 'bi-speedometer2' }}"></i><span class="sb-lbl">{{ $isStageUser ? 'My Work' : 'Dashboard' }}</span>
            </a>
            @unless($isStageUser)
            @can('view clients')
                <a href="{{ route('clients.index') }}" class="sb-link {{ request()->routeIs('clients.*') ? 'active' : '' }}"
                    title="Clients" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-people"></i><span class="sb-lbl">Clients</span>
                </a>
            @endcan
            @endunless
            @can('view payments')
                <a href="{{ route('payments.index') }}"
                    class="sb-link {{ request()->routeIs('payments.*') ? 'active' : '' }}" title="Payments"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-cash-coin"></i><span class="sb-lbl">Payments</span>
                </a>
            @endcan
            @unless($isStageUser)
            @can('view ads')
                <a href="{{ route('ads.index') }}"
                    class="sb-link {{ request()->routeIs('ads.*') || request()->routeIs('clients.ads.*') ? 'active' : '' }}"
                    title="Ads" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-megaphone"></i><span class="sb-lbl">Ads</span>
                </a>
            @endcan
            @endunless

            {{-- The all-meetings list exposes client names, so it follows client visibility. --}}
            @can('view clients')
                <a href="{{ route('meetings.all') }}"
                    class="sb-link {{ request()->routeIs('meetings.all') ? 'active' : '' }}" title="All Meetings"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-calendar-event"></i><span class="sb-lbl">Meetings</span>
                </a>
            @endcan
            @can('manage-meetings')
                <a href="{{ route('meetings.book') }}"
                    class="sb-link {{ request()->routeIs('meetings.book') ? 'active' : '' }}" title="Book a Meeting"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-calendar-plus"></i><span class="sb-lbl">Book Meeting</span>
                </a>
            @endcan
            @can('view tasks')
                <a href="{{ route('tasks.index') }}" class="sb-link {{ request()->routeIs('tasks.*') ? 'active' : '' }}"
                    title="Tasks" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-list-check"></i><span class="sb-lbl">Tasks</span>
                </a>
            @endcan
            @if($flowParticipant)
                <a href="{{ route('flow.queue') }}"
                    class="sb-link {{ request()->routeIs('flow.queue') || request()->routeIs('flow-items.*') ? 'active' : '' }}" title="My Queue"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-inboxes"></i><span class="sb-lbl">My Queue</span>
                    <span id="flowQueueBadge" style="{{ $flowQueueCount ? 'display:inline-flex' : 'display:none' }};margin-left:auto;background:var(--primary);color:#fff;font-size:.6rem;font-weight:700;border-radius:999px;padding:0 5px;min-width:16px;height:16px;align-items:center;justify-content:center">{{ $flowQueueCount ?: '' }}</span>
                </a>
            @endif
            @can('manage workflows')
                <a href="{{ route('workflows.index') }}"
                    class="sb-link {{ request()->routeIs('workflows.*') ? 'active' : '' }}" title="Workflows"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-diagram-2"></i><span class="sb-lbl">Workflows</span>
                </a>
            @endcan
            @unless($isStageUser)
            <a href="{{ route('requests.index') }}"
                class="sb-link {{ request()->routeIs('requests.*') ? 'active' : '' }}" title="Requests"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-inbox"></i><span class="sb-lbl">Requests</span>
            </a>
            @endunless
            {{-- Deliberately open — outside the stage-user trim, since department
                 staff are exactly who this is for: anyone may post a review or
                 report. Reading other people's is gated by 'view reviews', both
                 in the controller and in the page itself. --}}
            <a href="{{ route('reviews.index') }}" class="sb-link {{ request()->routeIs('reviews.*') ? 'active' : '' }}"
                title="Reviews & Reports" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-chat-square-text"></i><span class="sb-lbl">Reviews & Reports</span>
            </a>
            @canany(['view ads', 'manage ads'])
                <a href="{{ route('marketing.index') }}"
                    class="sb-link {{ request()->routeIs('marketing.*') ? 'active' : '' }}" title="Marketing"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-megaphone"></i><span class="sb-lbl">Marketing</span>
                </a>
            @endcanany
            @can('view performance')
                <a href="{{ route('performance.index') }}"
                    class="sb-link {{ request()->routeIs('performance.*') ? 'active' : '' }}" title="Performance"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-graph-up-arrow"></i><span class="sb-lbl">Performance</span>
                </a>
            @endcan
            @php $chatUnread = app(\App\Services\ChatService::class)->unreadCountFor(auth()->user()); @endphp
            <a href="{{ route('chat.index') }}"
                class="sb-link {{ request()->routeIs('chat.index') || request()->routeIs('chat.open') ? 'active' : '' }}" title="Chat"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-chat-dots"></i><span class="sb-lbl">Chat</span>
                <span id="chatUnreadBadge" style="{{ $chatUnread ? 'display:inline-flex' : 'display:none' }};margin-left:auto;background:var(--primary);color:#fff;font-size:.6rem;font-weight:700;border-radius:999px;padding:0 5px;min-width:16px;height:16px;align-items:center;justify-content:center">{{ $chatUnread ?: '' }}</span>
            </a>
            @can('monitor chats')
                <a href="{{ route('chat.monitor') }}"
                    class="sb-link {{ request()->routeIs('chat.monitor*') ? 'active' : '' }}" title="Chat Monitor"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-eye"></i><span class="sb-lbl">Chat Monitor</span>
                </a>
            @endcan

            @unless($isStageUser)
            @canany(['import clients', 'export clients', 'manage-workflow', 'view file-manager'])
                <div class="sb-section">Operations</div>
            @endcanany
            @can('import clients')
                <a href="{{ route('import.index') }}" class="sb-link {{ request()->routeIs('import.*') ? 'active' : '' }}"
                    title="Import Data" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-upload"></i><span class="sb-lbl">Import Data</span>
                </a>
            @endcan
            @can('export clients')
                <a href="#" id="exportSidebarBtn" class="sb-link" title="Export Data" data-bs-toggle="tooltip"
                    data-bs-placement="right">
                    <i class="bi bi-download"></i><span class="sb-lbl">Export Data</span>
                </a>
            @endcan
            {{-- The legacy departmental pipeline is retired from the menu: the flow
                 engine under "Workflows" is now the client pipeline. Its routes stay
                 registered because the client portal journey still renders from it —
                 see DEPLOYMENT.md for the remaining migration steps. --}}
            @can('view file-manager')
                <a href="{{ route('file-manager.index') }}"
                    class="sb-link {{ request()->routeIs('file-manager.*') ? 'active' : '' }}" title="File Manager"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-folder2-open"></i><span class="sb-lbl">File Manager</span>
                </a>
            @endcan
            @endunless

            {{-- Approving changes is a manager job, not an admin one, so it sits
                 under Management. Only genuinely Super Admin-only tools carry the
                 Administration heading below. --}}
            @php $canApproveChanges = auth()->user()->hasAnyRole(['Super Admin', 'Manager']); @endphp
            @if($canApproveChanges || auth()->user()->canAny(['manage categories', 'manage users']))
                <div class="sb-section">Management</div>
            @endif
            @can('manage categories')
                <a href="{{ route('categories.index') }}"
                    class="sb-link {{ request()->routeIs('categories.*') ? 'active' : '' }}" title="Categories"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-tags"></i><span class="sb-lbl">Categories</span>
                </a>
            @endcan
            @can('manage users')
                <a href="{{ route('users.index') }}" class="sb-link {{ request()->routeIs('users.*') ? 'active' : '' }}"
                    title="Users" data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-person-gear"></i><span class="sb-lbl">Users</span>
                </a>
            @endcan

            @if($canApproveChanges)
                <a href="{{ route('pending-changes.index') }}"
                    class="sb-link {{ request()->routeIs('pending-changes.*') ? 'active' : '' }}" title="Pending Changes"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-hourglass-split"></i><span class="sb-lbl">Pending Changes</span>
                </a>
            @endif

            @role('Super Admin')
            <div class="sb-section">Administration</div>
            <a href="{{ route('roles.index') }}"
                class="sb-link {{ request()->routeIs('roles.*', 'permissions.*') ? 'active' : '' }}"
                title="Roles & Permissions" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-shield-lock"></i><span class="sb-lbl">Roles & Permissions</span>
            </a>
            <a href="{{ route('settings.index') }}"
                class="sb-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" title="Settings"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-gear"></i><span class="sb-lbl">Settings</span>
            </a>
            @endrole
        </nav>
    </aside>

    <div id="sidebarOverlay"></div>

    {{-- ── Topbar ───────────────────────────────────────────────────── --}}
    <header id="topbar">
        <button id="sidebarToggle" class="btn btn-sm d-flex align-items-center justify-content-center flex-shrink-0"
            style="width:35px;height:35px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text2);padding:0">
            <i class="bi bi-list" style="font-size:1.15rem"></i>
        </button>

        {{-- Searches clients, so it follows client visibility like every other client surface. --}}
        @can('view clients')
            <div class="tb-search">
                <div class="tb-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="globalSearch" placeholder="Search clients, DFID, brand…" autocomplete="off">
                    <span class="tb-kbd d-none d-sm-inline">/</span>
                </div>
                <div id="searchDropdown"></div>
            </div>
        @endcan

        <div class="ms-auto d-flex align-items-center gap-2">
            <button id="darkToggle" class="btn btn-sm d-flex align-items-center justify-content-center"
                style="width:35px;height:35px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text2);padding:0"
                title="Toggle theme">
                <i id="darkIcon" class="bi bi-moon-stars" style="font-size:.88rem"></i>
            </button>

            {{-- Shown only while the websocket is down — see the state_change
                 binding below. Chat alerts and desktop notifications have no
                 other delivery path, so a silent socket is worth surfacing. --}}
            <span id="realtimePill" style="display:none;align-items:center;gap:.3rem;
                background:var(--c-yellow-bg);color:var(--c-yellow);border:1px solid var(--c-yellow);
                border-radius:999px;font-size:.66rem;font-weight:600;padding:.15rem .5rem;height:26px">
                <i class="bi bi-broadcast"></i>Live off
            </span>

            <div class="dropdown">
                <button class="btn btn-sm d-flex align-items-center justify-content-center position-relative"
                    data-bs-toggle="dropdown" id="notifBell"
                    style="width:35px;height:35px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text2);padding:0"
                    title="Notifications">
                    <i class="bi bi-bell" style="font-size:.88rem"></i>
                    <span id="notifBadge" class="d-none position-absolute"
                        style="top:-4px;right:-4px;background:var(--c-red);color:#fff;border-radius:20px;font-size:.6rem;font-weight:700;padding:1px 5px;line-height:1.3">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end mt-1 p-0"
                    style="min-width:320px;max-height:420px;overflow-y:auto">
                    <div class="d-flex align-items-center justify-content-between px-3 py-2"
                        style="border-bottom:1px solid var(--border)">
                        <span class="fw-bold" style="font-size:.8rem">Notifications</span>
                        <button id="notifMarkAll" class="btn btn-sm p-0"
                            style="font-size:.68rem;color:var(--primary);background:none;border:none">Mark all
                            read</button>
                    </div>
                    <div id="notifList">
                        <div class="text-center py-4">
                            <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
                        </div>
                    </div>
                </div>
            </div>

            @can('manage clients')
                <a href="{{ route('clients.create') }}"
                    class="btn btn-primary btn-sm d-none d-md-flex align-items-center gap-1"
                    style="height:33px;padding:0 12px;font-size:.78rem">
                    <i class="bi bi-plus-lg"></i><span>New Client</span>
                </a>
            @endcan

            <div class="dropdown">
                <button class="btn p-0 d-flex align-items-center gap-2" data-bs-toggle="dropdown"
                    style="background:none;border:none">
                    <div class="user-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                    <span class="d-none d-md-block"
                        style="font-size:.76rem;font-weight:500;color:var(--text2)">{{ Str::limit(auth()->user()->name, 18) }}</span>
                    <i class="bi bi-chevron-down d-none d-md-block" style="font-size:.58rem;color:var(--text3)"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end mt-1" style="min-width:200px">
                    <li class="px-3 py-2">
                        <div style="font-size:.77rem;font-weight:600;color:var(--text)">{{ auth()->user()->name }}</div>
                        <div style="font-size:.68rem;color:var(--text3)">{{ auth()->user()->email }}</div>
                        @foreach(auth()->user()->getRoleNames() as $roleName)
                            <span class="badge mt-1 me-1"
                                style="background:rgba(var(--primary-rgb),.12);color:var(--primary);font-size:.6rem">{{ $roleName }}</span>
                        @endforeach
                    </li>
                    <li>
                        <hr class="dropdown-divider my-1">
                    </li>
                    <li>
                        <label class="dropdown-item d-flex align-items-center justify-content-between mb-0" style="cursor:pointer" onclick="event.stopPropagation()">
                            <span><i class="bi bi-volume-up me-2"></i>Alert sounds</span>
                            <div class="form-check form-switch m-0 ms-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="soundToggle" style="cursor:pointer">
                            </div>
                        </label>
                    </li>
                    <li>
                        <label class="dropdown-item d-flex align-items-center justify-content-between mb-0" style="cursor:pointer" onclick="event.stopPropagation()">
                            <span>
                                <i class="bi bi-bell me-2"></i>Desktop notifications
                                <span id="desktopNotifyHint" class="d-block" style="font-size:.62rem;color:var(--text3);margin-left:1.55rem"></span>
                            </span>
                            <div class="form-check form-switch m-0 ms-3">
                                <input class="form-check-input" type="checkbox" role="switch" id="chatDesktopToggle" style="cursor:pointer">
                            </div>
                        </label>
                    </li>
                    <li>
                        <hr class="dropdown-divider my-1">
                    </li>
                    @role('Super Admin')
                    <li><a class="dropdown-item" href="{{ route('settings.index') }}"><i
                                class="bi bi-gear me-2"></i>Settings</a></li>
                    @endrole
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item" style="color:#ef4444"><i
                                    class="bi bi-box-arrow-right me-2"></i>Sign out</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    {{-- ── Main ─────────────────────────────────────────────────────── --}}
    <main id="main">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show py-2 mb-3 d-flex align-items-center gap-2"
                role="alert">
                <i class="bi bi-check-circle-fill flex-shrink-0"></i>
                <span>{{ session('success') }}</span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:.7rem"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show py-2 mb-3 d-flex align-items-center gap-2"
                role="alert">
                <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
                <span>{{ session('error') }}</span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" style="font-size:.7rem"></button>
            </div>
        @endif

        @yield('content')
    </main>

    {{-- ── Quick View Drawer (global) ───────────────────────────────── --}}
    <div id="qvBackdrop" onclick="closeDrawer()"></div>
    <aside id="qvDrawer">
        <div class="qv-header">
            <div class="qv-avatar" id="qvAvatar">?</div>
            <div class="flex-fill min-w-0">
                <div style="font-size:.88rem;font-weight:600;color:var(--text)" class="text-truncate" id="qvName">
                    Loading…</div>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span id="qvDfid" class="badge"
                        style="background:var(--surface2);color:var(--text2);border:1px solid var(--border);font-size:.62rem"></span>
                    <span id="qvStatus"></span>
                </div>
            </div>
            <div class="d-flex gap-1 ms-2 flex-shrink-0">
                <a id="qvEditLink" href="#" class="btn btn-sm btn-outline-secondary px-2" title="Edit"><i
                        class="bi bi-pencil"></i></a>
                <button onclick="closeDrawer()" class="btn btn-sm btn-outline-secondary px-2"><i
                        class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="qv-body" id="qvBody">
            <div class="text-center py-5">
                <div class="spinner-border spinner-border-sm" style="color:var(--text3)"></div>
            </div>
        </div>
    </aside>

    {{-- ── Bulk action bar ──────────────────────────────────────────── --}}
    <div id="bulkBar">
        <span><strong id="bulkCount">0</strong> selected</span>
        <div class="bb-sep"></div>
        <button id="bulkAssignBtn" class="btn btn-sm"
            style="background:rgba(37,99,235,.18);color:#60a5fa;border:none;padding:3px 10px">
            <i class="bi bi-person-check me-1"></i>Assign
        </button>
        <button id="bulkTerminateBtn" class="btn btn-sm"
            style="background:rgba(127,29,29,.28);color:#fca5a5;border:none;padding:3px 10px">
            <i class="bi bi-slash-circle me-1"></i>Terminate
        </button>
        <button id="bulkDeleteBtn" class="btn btn-sm"
            style="background:rgba(239,68,68,.18);color:#f87171;border:none;padding:3px 10px">
            <i class="bi bi-trash me-1"></i>Delete
        </button>
        <button onclick="$('#selectAll').prop('checked',false).trigger('change')" class="btn btn-sm"
            style="background:rgba(255,255,255,.08);color:rgba(255,255,255,.7);border:none;padding:3px 10px">
            Cancel
        </button>
    </div>

    {{-- ── Bulk Assign Modal ─────────────────────────────────────────── --}}
    <div class="modal fade" id="bulkAssignModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2 px-3">
                    <h6 class="modal-title"><i class="bi bi-person-check me-2"></i>Assign <span
                            id="bulkAssignCount">0</span> Clients</h6>
                    <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-3 py-3">
                    <label class="form-label small fw-semibold">Assign to</label>
                    <select id="bulkAssignOwner" class="form-select form-select-sm mb-2"></select>
                    <label class="form-label small fw-semibold">Note (optional)</label>
                    <textarea id="bulkAssignNote" class="form-control form-control-sm" rows="2"></textarea>
                </div>
                <div class="modal-footer py-2 px-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button id="bulkAssignConfirm" class="btn btn-sm btn-primary">Assign</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Export Modal ─────────────────────────────────────────────── --}}
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2 px-3">
                    <h6 class="modal-title"><i class="bi bi-download me-2"></i>Export Clients</h6>
                    <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body px-3 py-3">
                    @foreach(['excel' => ['bi-file-earmark-spreadsheet', 'success', 'Excel (.xlsx)'], 'csv' => ['bi-filetype-csv', 'primary', 'CSV (.csv)'], 'pdf' => ['bi-file-earmark-pdf', 'danger', 'PDF']] as $val => [$icon, $clr, $label])
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="exportFmt" value="{{ $val }}"
                                id="fmt{{ $val }}" {{ $val === 'excel' ? 'checked' : '' }}>
                            <label class="form-check-label" for="fmt{{ $val }}">
                                <i class="bi {{ $icon }} text-{{ $clr }} me-1"></i>{{ $label }}
                            </label>
                        </div>
                    @endforeach
                </div>
                <div class="modal-footer py-2 px-3">
                    <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="doExport" class="btn btn-sm btn-primary"><i
                            class="bi bi-download me-1"></i>Download</a>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ App\Support\ShellAsset::url('vendor/js/jquery.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/jquery.dataTables.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/dataTables.bootstrap5.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/sweetalert2.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/select2.min.js') }}"></script>
    {{-- Chart.js is NOT loaded here: it is 205 KB and only three pages draw
         charts. Those pages pull it in themselves via @push('scripts'). --}}
    <script src="{{ App\Support\ShellAsset::url('vendor/js/pusher.min.js') }}"></script>
    <script src="{{ App\Support\ShellAsset::url('vendor/js/echo.iife.js') }}"></script>
    {{-- Per-request values for the cached shell scripts. Small, and deliberately
         inline: the CSRF token is per session and must never be baked into a
         cacheable file. --}}
    @php
        // Built here rather than inside @json(...): Blade cannot parse a
        // multi-line array literal as a directive argument.
        $dfcpConfig = [
            'csrf'   => csrf_token(),
            'sounds' => [
                'message'      => asset('sounds/message_alert.mp3'),
                'notification' => asset('sounds/notification.mp3'),
            ],
            'routes' => [
                'notifications.index'    => route('notifications.index'),
                'notifications.read-all' => route('notifications.read-all'),
                'clients.bulk-delete'    => route('clients.bulk-delete'),
                'clients.bulk-terminate' => route('clients.bulk-terminate'),
                'clients.bulk-assign'    => route('clients.bulk-assign'),
                'export.clients'         => route('export.clients'),
                'search.global'          => route('search.global'),
                'clients.index'          => route('clients.index'),
                'dashboard'              => route('dashboard'),
            ],
            'urls' => [
                'notifications' => url('notifications'),
            ],
        ];
    @endphp
    <script>
        window.DFCP = @json($dfcpConfig);
    </script>
    <script src="{{ App\Support\ShellAsset::url('js/shell-a.js') }}"></script>

    {{-- Realtime (Laravel Reverb) — presence + personal notifications, app-wide --}}
    @php
        $reverbKey    = config('broadcasting.connections.reverb.key');
        $reverbHost   = config('broadcasting.connections.reverb.options.host');
        $reverbScheme = config('broadcasting.connections.reverb.options.scheme') ?: 'https';
        $reverbPort   = (int) (config('broadcasting.connections.reverb.options.port') ?: ($reverbScheme === 'https' ? 443 : 80));
        $reverbReady  = filled($reverbKey) && filled($reverbHost);

        // Two misconfigurations that look fine on a developer's own machine and
        // can never work for anyone else. Both are silent without this: the app
        // loads, the websocket quietly fails, and desktop notifications simply
        // never arrive.
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $appIsHttps = str_starts_with((string) config('app.url'), 'https://');
        $reverbIsLoopback = in_array($reverbHost, ['127.0.0.1', 'localhost', '::1'], true);

        $realtimeWarning = null;
        if ($reverbReady && $reverbIsLoopback && $appHost && !in_array($appHost, ['127.0.0.1', 'localhost'], true)) {
            // The browser resolves REVERB_HOST on the *visitor's* machine.
            $realtimeWarning = 'REVERB_HOST is ' . $reverbHost . ' but the app is served from ' . $appHost
                . '. Every browser will try to open the websocket against its own machine, so live updates '
                . 'and desktop notifications will never arrive. Set REVERB_HOST=' . $appHost . '.';
        } elseif ($reverbReady && $appIsHttps && $reverbScheme !== 'https') {
            // Browsers refuse a plain ws:// socket from an https page.
            $realtimeWarning = 'The app is served over HTTPS but REVERB_SCHEME is ' . $reverbScheme
                . '. Browsers block an insecure websocket from a secure page. Set REVERB_SCHEME=https '
                . 'and proxy the websocket through your web server.';
        }
    @endphp

    @if($realtimeWarning && auth()->user()->hasRole('Super Admin'))
        <div class="alert alert-warning py-2 px-3 mb-0" style="font-size:.78rem;border-radius:0">
            <i class="bi bi-broadcast me-1"></i><strong>Live updates misconfigured.</strong>
            {{ $realtimeWarning }}
        </div>
    @endif
    <script>
        window.CURRENT_USER_ID = {{ auth()->id() }};
        window.OnlineUsers = new Set();
    @if(! $reverbReady)
        // Reverb is not configured in this environment. Deliberately skip Echo:
        // constructing it with a blank host makes pusher-js fall back to its own
        // cloud default (wss://ws-.pusher.com) and spam failed connections.
        console.warn(
            'Realtime disabled — REVERB_APP_KEY and REVERB_HOST are empty. ' +
            'Set them in .env, then run: php artisan config:cache && php artisan reverb:restart'
        );
    @else
        try {
            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: @json($reverbKey),
                wsHost: @json($reverbHost),
                wsPort: {{ $reverbPort }},
                wssPort: {{ $reverbPort }},
                forceTLS: {{ $reverbScheme === 'https' ? 'true' : 'false' }},
                // Both names are required. In pusher-js these are transport
                // IMPLEMENTATION names, not URL schemes: with forceTLS the strategy
                // uses the transport named 'ws' and gives it a wss:// URL. Listing
                // only 'wss' leaves the TLS path with no usable transport, so
                // isSupported() fails and the connection state goes straight to
                // "failed" without a single network request.
                enabledTransports: ['ws', 'wss'],
            });

            // Say so when the socket is down. Without this the app looks
            // perfectly healthy while chat messages and desktop notifications
            // silently never arrive — they have no other delivery path.
            (function () {
                var connection = window.Echo.connector.pusher.connection;

                connection.bind('state_change', function (states) {
                    var pill = document.getElementById('realtimePill');
                    if (!pill) return;

                    var down = states.current !== 'connected';
                    pill.style.display = down ? 'inline-flex' : 'none';
                    pill.title = down
                        ? 'Live updates are not connected (' + states.current + '). Chat alerts and '
                          + 'desktop notifications will not arrive until this reconnects.'
                        : '';
                });
            })();

            // App-wide presence: who is currently online.
            window.Echo.join('online')
                .here(function (users) { window.OnlineUsers = new Set(users.map(u => u.id)); document.dispatchEvent(new CustomEvent('online-changed')); })
                .joining(function (u) { window.OnlineUsers.add(u.id); document.dispatchEvent(new CustomEvent('online-changed', { detail: u })); })
                .leaving(function (u) { window.OnlineUsers.delete(u.id); document.dispatchEvent(new CustomEvent('online-changed', { detail: u })); });

            // Personal channel: new messages to me → bump the nav unread badge + notify open pages.
            window.Echo.private('App.Models.User.' + window.CURRENT_USER_ID)
                .listen('.message.sent', function (e) {
                    document.dispatchEvent(new CustomEvent('chat-message', { detail: e }));
                    if (window.AppSound) window.AppSound.message();
                    if (window.ChatNotify) window.ChatNotify.show(e);
                    if (window.ActiveConversationId !== e.conversation_id) {
                        var badge = document.getElementById('chatUnreadBadge');
                        if (badge) { badge.textContent = (parseInt(badge.textContent || '0', 10) || 0) + 1; badge.style.display = 'inline-flex'; }
                        showMessageToast(e);
                    }
                })
                .notification(function (n) {
                    // Any broadcast notification → refresh the bell live.
                    if (typeof loadNotifications === 'function') loadNotifications();
                    // Work arrived in a workflow stage → bump the My Queue badge.
                    if (n && n.type && n.type.indexOf('FlowItemAwaitingYou') !== -1) {
                        var q = document.getElementById('flowQueueBadge');
                        if (q) { q.textContent = (parseInt(q.textContent || '0', 10) || 0) + 1; q.style.display = 'inline-flex'; }
                    }
                });
        } catch (err) {
            console.warn('Realtime (Reverb/Echo) unavailable:', err);
        }
    @endif
    </script>
    <script src="{{ App\Support\ShellAsset::url('js/shell-b.js') }}"></script>

    {{-- App-wide audio calling. Included here so an incoming call reaches the
         user on any page, and before @stack('scripts') so its JS is queued. --}}
    @include('calls.panel')

    @stack('scripts')
</body>

</html>