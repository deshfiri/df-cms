<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $appName = \App\Models\Setting::get('app_name', 'DFCP COMS');
        $appLogo = \App\Models\Setting::get('app_logo');
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

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">

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
        }

        /* ── Reset / Base ───────────────────────────────────────────── */
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html {
            font-size: 14px;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--text);
            -webkit-font-smoothing: antialiased;
        }

        /* ── Dark mode depth & atmosphere ──────────────────────────── */
        [data-theme="dark"] body {
            background-color: #0B1220;
            background-image:
                radial-gradient(ellipse 80% 50% at 15% 10%, rgba(20, 33, 61, .7) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 85% 85%, rgba(15, 23, 42, .8) 0%, transparent 55%),
                radial-gradient(ellipse 40% 30% at 50% 50%, rgba(14, 21, 61, .3) 0%, transparent 70%);
            background-attachment: fixed;
        }

        /* ── Dark mode card elevation ───────────────────────────────── */
        [data-theme="dark"] .card {
            background: #111827 !important;
            border: 1px solid #1f2d40 !important;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .45), inset 0 1px 0 rgba(255, 255, 255, .04) !important;
        }

        [data-theme="dark"] .card:hover {
            border-color: #2a3d55 !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .55), inset 0 1px 0 rgba(255, 255, 255, .06) !important;
        }

        [data-theme="dark"] .card-header {
            background: rgba(255, 255, 255, .025) !important;
            border-color: #1f2d40 !important;
        }

        /* ── Dark mode topbar ───────────────────────────────────────── */
        [data-theme="dark"] #topbar {
            background: rgba(11, 18, 32, .92);
            border-bottom-color: #1f2d40;
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        [data-theme="dark"] .tb-search-box {
            background: #1a2235;
            border-color: #1f2d40;
        }

        [data-theme="dark"] .tb-search-box:focus-within {
            background: #1f2a40;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .2);
        }

        [data-theme="dark"] .tb-kbd {
            background: #1a2235;
            border-color: #2a3d55;
            color: var(--text3);
        }

        /* ── Dark mode sidebar upgrades ─────────────────────────────── */
        [data-theme="dark"] #sidebar {
            --sb-bg-top:
                {{ $sbBgTopDarkMode }}
            ;
            --sb-bg-bottom:
                {{ $sbBgBottomDarkMode }}
            ;
            border-right: 1px solid #1a2a38;
        }

        [data-theme="dark"] .sb-brand {
            border-bottom-color: #1a2a38;
        }

        [data-theme="dark"] .sb-link:hover {
            background: rgba(255, 255, 255, .05);
            color: #F8FAFC;
        }

        [data-theme="dark"] .sb-link.active {
            background: rgba(var(--primary-rgb), .16);
            color: #F8FAFC;
            box-shadow: inset 3px 0 0 var(--primary);
        }

        [data-theme="dark"] .sb-link.active i {
            color: var(--primary);
        }

        [data-theme="dark"] .sb-section {
            color: rgba(255, 255, 255, .3);
            letter-spacing: .1em;
        }

        /* ── Dark mode table depth ──────────────────────────────────── */
        [data-theme="dark"] .table thead th {
            background: rgba(255, 255, 255, .03) !important;
            border-color: rgba(255, 255, 255, .08) !important;
            color: var(--text3);
            letter-spacing: .06em;
        }

        [data-theme="dark"] .table tbody td {
            border-color: rgba(255, 255, 255, .05) !important;
        }

        [data-theme="dark"] .table tbody tr:hover {
            background: rgba(255, 255, 255, .04) !important;
        }

        /* ── Dark mode dropdown depth ───────────────────────────────── */
        [data-theme="dark"] .dropdown-menu {
            background: #141e2e;
            border-color: #2a3d55;
            box-shadow: 0 16px 48px rgba(0, 0, 0, .65);
        }

        [data-theme="dark"] .dropdown-item:hover {
            background: #1a2a3e;
            color: var(--text);
        }

        /* ── Dark mode modal depth ──────────────────────────────────── */
        [data-theme="dark"] .modal-content {
            background: #111827;
            border-color: #2a3d55;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .7);
        }

        [data-theme="dark"] .modal-header,
        [data-theme="dark"] .modal-footer {
            background: #0e1929;
            border-color: #1f2d40;
        }

        /* ── Dark mode quick-view drawer ────────────────────────────── */
        [data-theme="dark"] #qvDrawer {
            background: #111827;
            border-left-color: #1f2d40;
        }

        [data-theme="dark"] .qv-info-box {
            background: #1a2235;
            border: 1px solid #1f2d40;
        }

        /* ── Dark mode KPI icon glow ────────────────────────────────── */
        [data-theme="dark"] .dash-kpi-icon i,
        [data-theme="dark"] .kpi-icon i {
            filter: drop-shadow(0 0 7px currentColor);
        }

        [data-theme="dark"] .kpi-card:hover,
        [data-theme="dark"] .dash-kpi:hover {
            border-color: rgba(255, 255, 255, .15) !important;
            background: #1a2235 !important;
        }

        /* ── Dark mode filter pills ─────────────────────────────────── */
        [data-theme="dark"] .fpill {
            background: #1a2235;
            border-color: #2a3d55;
            color: var(--text2);
        }

        [data-theme="dark"] .fpill:hover {
            border-color: var(--c-blue);
            color: var(--c-blue);
            background: rgba(59, 130, 246, .08);
        }

        [data-theme="dark"] .fpill.active {
            background: var(--c-blue);
            border-color: var(--c-blue);
            color: #fff;
        }

        /* ── Dark mode quick view backdrop ──────────────────────────── */
        [data-theme="dark"] #qvBackdrop {
            background: rgba(0, 0, 0, .6);
        }

        /* ── Dark mode bulk bar ─────────────────────────────────────── */
        [data-theme="dark"] #bulkBar {
            background: #141e2e;
            border: 1px solid #2a3d55;
            color: var(--text);
        }

        /* ── Dark mode form controls ─────────────────────────────────── */
        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background: #1a2235 !important;
            border-color: #2a3d55 !important;
            color: var(--text) !important;
        }

        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus {
            background: #1f2a40 !important;
            border-color: var(--c-blue) !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .2) !important;
        }

        [data-theme="dark"] .input-group-text {
            background: #141e2e !important;
            border-color: #2a3d55 !important;
            color: var(--text3);
        }

        [data-theme="dark"] div.dataTables_wrapper .dataTables_length select,
        [data-theme="dark"] div.dataTables_wrapper .dataTables_filter input {
            background: #1a2235 !important;
            border-color: #2a3d55 !important;
        }

        [data-theme="dark"] .select2-container--bootstrap-5 .select2-selection {
            background: #1a2235 !important;
            border-color: #2a3d55 !important;
        }

        [data-theme="dark"] .select2-dropdown {
            background: #141e2e !important;
            border-color: #2a3d55 !important;
        }

        [data-theme="dark"] .select2-search--dropdown .select2-search__field {
            background: #1a2235 !important;
            border-color: #2a3d55 !important;
        }

        [data-theme="dark"] .swal2-popup {
            background: #111827 !important;
            border: 1px solid #2a3d55 !important;
        }

        [data-theme="dark"] .swal2-input {
            background: #1a2235 !important;
            border-color: #2a3d55 !important;
            color: var(--text) !important;
        }

        /* ── Bootstrap theme overrides ──────────────────────────────── */
        .btn-primary {
            --bs-btn-bg:
                {{ $themeColor }}
            ;
            --bs-btn-border-color:
                {{ $themeColor }}
            ;
            --bs-btn-hover-bg:
                {{ $themeColorDark }}
            ;
            --bs-btn-hover-border-color:
                {{ $themeColorDark }}
            ;
            --bs-btn-active-bg:
                {{ $themeColorDark }}
            ;
            --bs-btn-active-border-color:
                {{ $themeColorDark }}
            ;
            --bs-btn-disabled-bg:
                {{ $themeColor }}
            ;
            --bs-btn-disabled-border-color:
                {{ $themeColor }}
            ;
            --bs-btn-focus-shadow-rgb:
                {{ $tR }}
                ,
                {{ $tG }}
                ,
                {{ $tB }}
            ;
        }

        .btn-outline-primary {
            --bs-btn-color:
                {{ $themeColor }}
            ;
            --bs-btn-border-color:
                {{ $themeColor }}
            ;
            --bs-btn-hover-bg:
                {{ $themeColor }}
            ;
            --bs-btn-hover-border-color:
                {{ $themeColor }}
            ;
            --bs-btn-active-bg:
                {{ $themeColor }}
            ;
            --bs-btn-active-border-color:
                {{ $themeColor }}
            ;
            --bs-btn-focus-shadow-rgb:
                {{ $tR }}
                ,
                {{ $tG }}
                ,
                {{ $tB }}
            ;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .12);
        }

        /* ── Sidebar ────────────────────────────────────────────────── */
        #sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-w);
            height: 100vh;
            background: linear-gradient(180deg, var(--sb-bg-top), var(--sb-bg-bottom));
            display: flex;
            flex-direction: column;
            z-index: 1040;
            transition: width var(--t), transform var(--t);
            overflow: hidden;
        }

        .sb-brand {
            padding: 0 16px;
            height: var(--topbar-h);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--sb-bd);
            flex-shrink: 0;
            overflow: hidden;
            white-space: nowrap;
        }

        .sb-brand-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(var(--primary-rgb), .28);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .sb-brand-name {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .sb-brand-sub {
            font-size: .72rem;
            color: var(--sb-text);
        }

        .sb-brand-logo {
            max-height: 30px;
            max-width: 120px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .sb-brand-text {
            overflow: hidden;
            transition: opacity var(--t), width var(--t);
        }

        .sb-nav {
            padding: 8px 7px;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .sb-nav::-webkit-scrollbar {
            width: 2px;
        }

        .sb-nav::-webkit-scrollbar-thumb {
            background: var(--sb-bd);
        }

        .sb-section {
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--sb-text);
            padding: 14px 10px 5px;
            white-space: nowrap;
            transition: opacity var(--t), height var(--t), padding var(--t);
        }

        .sb-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 13px;
            border-radius: 7px;
            color: var(--sb-text);
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            white-space: nowrap;
            margin-bottom: 2px;
            transition: background var(--t), color var(--t);
        }

        .sb-link:hover {
            background: var(--sb-hover);
            color: rgba(255, 255, 255, .85);
        }

        .sb-link.active {
            background: var(--sb-active);
            color: #fff;
            box-shadow: inset 3px 0 0 var(--primary);
        }

        .sb-link i {
            font-size: 1.15rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }

        .sb-link.active i {
            color: var(--primary);
        }

        .sb-link .sb-lbl {
            overflow: hidden;
            transition: opacity var(--t), width var(--t);
        }

        #sidebar.collapsed {
            width: var(--sidebar-mini);
        }

        #sidebar.collapsed .sb-brand-text {
            opacity: 0;
            width: 0;
        }

        #sidebar.collapsed .sb-brand-logo {
            max-width: 32px;
        }

        #sidebar.collapsed .sb-section {
            opacity: 0;
            height: 0;
            padding-top: 0;
            padding-bottom: 0;
            overflow: hidden;
        }

        #sidebar.collapsed .sb-link {
            justify-content: center;
            padding: 9px 0;
        }

        #sidebar.collapsed .sb-link .sb-lbl {
            opacity: 0;
            width: 0;
        }

        #sidebar.collapsed .sb-link i {
            width: auto;
        }

        /* ── Topbar ─────────────────────────────────────────────────── */
        #topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-w);
            right: 0;
            height: var(--topbar-h);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 16px;
            z-index: 1030;
            transition: left var(--t);
        }

        #topbar.collapsed {
            left: var(--sidebar-mini);
        }

        .tb-search {
            flex: 1;
            max-width: 380px;
            position: relative;
        }

        .tb-search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 0 12px;
            height: 36px;
            transition: border-color .15s, box-shadow .15s;
        }

        .tb-search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .1);
            background: var(--surface);
        }

        .tb-search-box i {
            color: var(--text3);
            font-size: .83rem;
            flex-shrink: 0;
        }

        .tb-search-box input {
            border: none;
            background: transparent;
            outline: none;
            font-size: .82rem;
            color: var(--text);
            flex: 1;
            font-family: inherit;
        }

        .tb-search-box input::placeholder {
            color: var(--text3);
        }

        .tb-kbd {
            font-size: .63rem;
            color: var(--text3);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 1px 5px;
        }

        #searchDropdown {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            min-width: max(100%, 300px);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            z-index: 9999;
            display: none;
            overflow: hidden;
        }

        .sr-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 12px;
            text-decoration: none;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            transition: background .1s;
        }

        .sr-item:hover {
            background: var(--surface2);
        }

        .sr-item:last-child {
            border-bottom: none;
        }

        .user-avatar {
            width: 33px;
            height: 33px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            font-size: .74rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* ── Main ───────────────────────────────────────────────────── */
        #main {
            margin-left: var(--sidebar-w);
            margin-top: var(--topbar-h);
            padding: 20px;
            min-height: calc(100vh - var(--topbar-h));
            transition: margin-left var(--t);
        }

        #main.collapsed {
            margin-left: var(--sidebar-mini);
        }

        /* ── Cards ──────────────────────────────────────────────────── */
        .card {
            background: var(--surface) !important;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            background: var(--surface2) !important;
            border-color: var(--border) !important;
            padding: .8rem 1rem;
        }

        .card-body {
            color: var(--text);
        }

        .section-card {
            border-radius: var(--radius) !important;
        }

        /* ── Tables ─────────────────────────────────────────────────── */
        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text);
        }

        .table thead th {
            font-size: .68rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--text3);
            background: var(--surface2) !important;
            border-color: var(--border) !important;
            padding: 9px 11px;
            white-space: nowrap;
        }

        .table tbody td {
            font-size: .79rem;
            padding: 9px 11px;
            vertical-align: middle;
            border-color: var(--border) !important;
            color: var(--text);
        }

        .table tbody tr {
            transition: background .1s;
        }

        .table tbody tr:hover {
            background: var(--surface2) !important;
        }

        /* ── Alerts ─────────────────────────────────────────────────── */
        .alert {
            border-radius: var(--radius);
            border: none;
            font-size: .79rem;
        }

        [data-theme="dark"] .alert-success {
            background: var(--c-green-bg);
            color: var(--c-green);
        }

        [data-theme="dark"] .alert-danger {
            background: var(--c-red-bg);
            color: var(--c-red);
        }

        [data-theme="dark"] .alert-warning {
            background: var(--c-yellow-bg);
            color: var(--c-yellow);
        }

        [data-theme="dark"] .alert-info {
            background: var(--c-blue-bg);
            color: var(--c-blue);
        }

        /* ── Forms ──────────────────────────────────────────────────── */
        .form-control,
        .form-select {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text);
            font-size: .79rem;
            border-radius: 7px;
            font-family: inherit;
        }

        .form-control::placeholder {
            color: var(--text3);
        }

        .form-label {
            font-size: .75rem;
            font-weight: 500;
            color: var(--text2);
        }

        /* form control dark mode handled in consolidated block below */

        /* ── Buttons ────────────────────────────────────────────────── */
        .btn {
            font-size: .77rem;
            font-weight: 500;
            border-radius: 7px;
        }

        .btn-sm {
            font-size: .74rem;
        }

        [data-theme="dark"] .btn-outline-secondary {
            --bs-btn-color: var(--text2);
            --bs-btn-border-color: #2a3d55;
            --bs-btn-hover-color: var(--text);
            --bs-btn-hover-bg: #1a2235;
            --bs-btn-hover-border-color: #3a5070;
            --bs-btn-active-color: var(--text);
            --bs-btn-active-bg: #1f2d40;
            --bs-btn-active-border-color: #3a5070;
        }

        [data-theme="dark"] .btn-secondary {
            --bs-btn-bg: #1a2235;
            --bs-btn-border-color: #2a3d55;
            --bs-btn-color: var(--text2);
            --bs-btn-hover-bg: #243248;
            --bs-btn-hover-border-color: #3a5070;
            --bs-btn-hover-color: var(--text);
        }

        [data-theme="dark"] .btn-success {
            --bs-btn-bg: var(--c-green);
            --bs-btn-border-color: var(--c-green);
            --bs-btn-color: #0a1a12;
            --bs-btn-hover-bg: #2bb583;
            --bs-btn-hover-border-color: #2bb583;
            --bs-btn-hover-color: #0a1a12;
        }

        [data-theme="dark"] .btn-danger {
            --bs-btn-bg: var(--c-red);
            --bs-btn-border-color: var(--c-red);
            --bs-btn-color: #1a0a0a;
            --bs-btn-hover-bg: #f05252;
            --bs-btn-hover-border-color: #f05252;
        }

        [data-theme="dark"] .btn-warning {
            --bs-btn-bg: var(--c-yellow);
            --bs-btn-border-color: var(--c-yellow);
            --bs-btn-color: #1a1200;
            --bs-btn-hover-bg: #f0a820;
            --bs-btn-hover-border-color: #f0a820;
        }

        [data-theme="dark"] .btn-light {
            --bs-btn-bg: rgba(255, 255, 255, .06);
            --bs-btn-border-color: rgba(255, 255, 255, .1);
            --bs-btn-color: var(--text);
            --bs-btn-hover-bg: rgba(255, 255, 255, .1);
            --bs-btn-hover-color: var(--text);
        }

        [data-theme="dark"] .btn-primary {
            --bs-btn-bg: var(--primary);
            --bs-btn-border-color: var(--primary);
            --bs-btn-hover-bg: var(--primary-dark);
            --bs-btn-hover-border-color: var(--primary-dark);
            --bs-btn-active-bg: var(--primary-dark);
            --bs-btn-active-border-color: var(--primary-dark);
            --bs-btn-color: #fff;
            box-shadow: 0 4px 14px rgba(var(--primary-rgb), .35);
        }

        [data-theme="dark"] .btn-primary:hover {
            box-shadow: 0 4px 18px rgba(var(--primary-rgb), .45);
        }

        /* ── Badges ─────────────────────────────────────────────────── */
        .badge {
            font-size: .65rem;
            font-weight: 600;
            letter-spacing: .01em;
            border-radius: 20px;
            padding: 3px 7px;
        }

        /* ── Status pills ───────────────────────────────────────────── */
        .spill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 9px 2px 7px;
            border-radius: 20px;
            font-size: .67rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .spill::before {
            content: '';
            width: 5px;
            height: 5px;
            border-radius: 50%;
            flex-shrink: 0;
            background: currentColor;
            opacity: .7;
        }

        .spill-running {
            background: var(--c-green-bg);
            color: var(--c-green);
        }

        .spill-warning {
            background: var(--c-yellow-bg);
            color: var(--c-yellow);
        }

        .spill-completed {
            background: var(--c-blue-bg);
            color: var(--c-blue);
        }

        .spill-hold {
            background: var(--c-slate-bg);
            color: var(--c-slate);
        }

        .spill-cancelled {
            background: var(--c-red-bg);
            color: var(--c-red);
        }

        .spill-terminated {
            background: var(--text);
            color: var(--surface);
            opacity: .85;
        }

        /* Workflow stage statuses */
        .spill-pending {
            background: var(--c-slate-bg);
            color: var(--c-slate);
        }

        .spill-in-progress {
            background: var(--c-blue-bg);
            color: var(--c-blue);
        }

        .spill-submitted {
            background: var(--c-purple-bg);
            color: var(--c-purple);
        }

        .spill-need-revision {
            background: var(--c-yellow-bg);
            color: var(--c-yellow);
        }

        .spill-approved {
            background: var(--c-green-bg);
            color: var(--c-green);
        }

        .spill-rejected {
            background: var(--c-red-bg);
            color: var(--c-red);
        }

        .spill-locked {
            background: var(--c-slate-bg);
            color: var(--c-slate);
            opacity: .7;
        }

        .spill-secondary {
            background: var(--secondary-bg);
            color: var(--secondary);
        }

        .spill-blocked {
            background: var(--c-red-bg);
            color: var(--c-red);
        }

        .spill-delayed {
            background: var(--c-yellow-bg);
            color: var(--c-yellow);
        }

        /* ── KPI cards ──────────────────────────────────────────────── */
        .kpi-card {
            display: block;
            text-decoration: none;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 16px;
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }

        .kpi-card:hover {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .08), var(--shadow-sm);
            transform: translateY(-2px);
        }

        .kpi-val {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -.04em;
            color: var(--text);
            line-height: 1;
        }

        .kpi-lbl {
            font-size: .67rem;
            font-weight: 500;
            color: var(--text3);
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .kpi-icon {
            width: 33px;
            height: 33px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .88rem;
        }

        /* ── Filter pills ───────────────────────────────────────────── */
        .fpill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            font-size: .72rem;
            font-weight: 500;
            border: 1px solid var(--border);
            color: var(--text2);
            background: var(--surface);
            white-space: nowrap;
            transition: all .12s;
            outline: none;
        }

        .fpill:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .fpill.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .fpill .fcnt {
            font-size: .62rem;
            opacity: .75;
        }

        /* ── Quick View Drawer ──────────────────────────────────────── */
        #qvBackdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .35);
            z-index: 1049;
            opacity: 0;
            pointer-events: none;
            transition: opacity .2s ease;
            backdrop-filter: blur(2px);
        }

        #qvBackdrop.show {
            opacity: 1;
            pointer-events: all;
        }

        #qvDrawer {
            position: fixed;
            top: 0;
            right: -500px;
            width: 460px;
            height: 100vh;
            background: var(--surface);
            border-left: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: right .25s cubic-bezier(.4, 0, .2, 1);
        }

        #qvDrawer.open {
            right: 0;
        }

        .qv-header {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 10px;
            flex-shrink: 0;
        }

        .qv-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }

        .qv-body::-webkit-scrollbar {
            width: 3px;
        }

        .qv-body::-webkit-scrollbar-thumb {
            background: var(--border);
        }

        .qv-avatar {
            width: 38px;
            height: 38px;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: .9rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .qv-sec {
            margin-bottom: 16px;
        }

        .qv-sec-title {
            font-size: .63rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text3);
            margin-bottom: 7px;
        }

        .qv-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px;
        }

        .qv-info-box {
            background: var(--surface2);
            border-radius: 7px;
            padding: 8px 10px;
        }

        .qv-info-lbl {
            font-size: .63rem;
            color: var(--text3);
            font-weight: 500;
        }

        .qv-info-val {
            font-size: .77rem;
            color: var(--text);
            font-weight: 500;
            margin-top: 1px;
        }

        /* ── Bulk action bar ────────────────────────────────────────── */
        #bulkBar {
            position: fixed;
            bottom: -80px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--text);
            color: var(--surface);
            padding: 8px 16px;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            z-index: 1100;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: bottom .28s cubic-bezier(.34, 1.56, .64, 1);
            white-space: nowrap;
            font-size: .77rem;
        }

        #bulkBar.show {
            bottom: 18px;
        }

        #bulkBar .bb-sep {
            width: 1px;
            height: 16px;
            background: rgba(255, 255, 255, .15);
            flex-shrink: 0;
        }

        /* ── Timeline ───────────────────────────────────────────────── */
        .tl {
            padding-left: 20px;
            position: relative;
        }

        .tl::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 8px;
            bottom: 4px;
            width: 1px;
            background: var(--border);
        }

        .tl-item {
            position: relative;
            padding-bottom: 13px;
        }

        .tl-dot {
            position: absolute;
            left: -19px;
            top: 3px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--border);
            border: 2px solid var(--surface);
        }

        .tl-dot.done {
            background: var(--primary);
        }

        /* ── Progress ───────────────────────────────────────────────── */
        .progress {
            height: 4px;
            background: var(--border);
            border-radius: 20px;
            overflow: visible;
        }

        .progress-bar {
            border-radius: 20px;
        }

        /* ── DataTables overrides ───────────────────────────────────── */
        div.dataTables_wrapper .dataTables_length select,
        div.dataTables_wrapper .dataTables_filter input {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text);
            border-radius: 6px;
            font-size: .77rem;
            font-family: inherit;
        }

        /* dataTables dark handled in consolidated block */
        div.dataTables_wrapper .dataTables_info {
            font-size: .72rem;
            color: var(--text3);
        }

        div.dataTables_wrapper .dataTables_paginate .paginate_button {
            border-radius: 6px !important;
            font-size: .76rem;
            color: var(--text2) !important;
        }

        div.dataTables_wrapper .dataTables_paginate .paginate_button.current,
        div.dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background: var(--primary) !important;
            border-color: var(--primary) !important;
            color: #fff !important;
        }

        div.dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) {
            background: var(--surface2) !important;
            border-color: var(--border) !important;
            color: var(--text) !important;
        }

        /* ── Dropdowns ──────────────────────────────────────────────── */
        .dropdown-menu {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            font-size: .79rem;
        }

        .dropdown-item {
            color: var(--text2);
            border-radius: 6px;
            padding: 6px 11px;
        }

        .dropdown-item:hover {
            background: var(--surface2);
            color: var(--text);
        }

        .dropdown-header {
            color: var(--text3);
            font-size: .66rem;
            font-weight: 600;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .dropdown-divider {
            border-color: var(--border);
        }

        /* ── Modals ─────────────────────────────────────────────────── */
        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .modal-header {
            border-color: var(--border);
            background: var(--surface2);
            border-radius: 12px 12px 0 0;
        }

        .modal-footer {
            border-color: var(--border);
            background: var(--surface2);
            border-radius: 0 0 12px 12px;
        }

        .modal-body {
            color: var(--text);
        }

        .modal-title {
            font-size: .88rem;
            font-weight: 600;
            color: var(--text);
        }

        .btn-close {
            filter: none;
        }

        [data-theme="dark"] .btn-close {
            filter: invert(1);
        }

        /* ── Typography ─────────────────────────────────────────────── */
        .page-title {
            font-size: var(--fs-h4);
            font-weight: var(--fw-bold);
            color: var(--text);
            letter-spacing: -.02em;
        }

        .section-title {
            font-size: var(--fs-h3);
            font-weight: var(--fw-bold);
            color: var(--text);
            letter-spacing: -.01em;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            color: var(--text);
            letter-spacing: -.02em;
        }

        .text-muted {
            color: var(--text3) !important;
        }

        small,
        .small {
            color: var(--text2);
        }

        /* ── Select2 remaining overrides ────────────────────────────── */
        [data-theme="dark"] .select2-container--bootstrap-5 .select2-selection__rendered {
            color: var(--text);
        }

        [data-theme="dark"] .select2-container--bootstrap-5 .select2-selection__placeholder {
            color: var(--text3);
        }

        [data-theme="dark"] .select2-results__option {
            color: var(--text);
        }

        [data-theme="dark"] .select2-results__option--highlighted[aria-selected] {
            background: var(--primary) !important;
        }

        [data-theme="dark"] .swal2-title {
            color: var(--text);
        }

        /* ── Bootstrap contextual class overrides for dark mode ─────── */
        [data-theme="dark"] .bg-white {
            background: var(--surface) !important;
        }

        [data-theme="dark"] .bg-light {
            background: var(--surface2) !important;
        }

        [data-theme="dark"] .border-light {
            border-color: var(--border) !important;
        }

        [data-theme="dark"] .text-dark {
            color: var(--text) !important;
        }

        [data-theme="dark"] .text-black {
            color: var(--text) !important;
        }

        [data-theme="dark"] .badge.bg-light {
            background: var(--surface2) !important;
            color: var(--text2) !important;
            border-color: var(--border) !important;
        }

        [data-theme="dark"] .form-check-input {
            background-color: var(--surface2);
            border-color: var(--border);
        }

        [data-theme="dark"] .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        [data-theme="dark"] .table-hover>tbody>tr:hover>* {
            --bs-table-hover-bg: var(--surface2);
            --bs-table-hover-color: var(--text);
        }

        [data-theme="dark"] .nav-tabs {
            border-color: var(--border);
        }

        [data-theme="dark"] .nav-tabs .nav-link {
            color: var(--text2);
        }

        [data-theme="dark"] .nav-tabs .nav-link.active {
            background: var(--surface);
            border-color: var(--border) var(--border) var(--surface);
            color: var(--primary);
        }

        [data-theme="dark"] .list-group-item {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text);
        }

        [data-theme="dark"] hr {
            border-color: var(--border);
        }

        [data-theme="dark"] .text-success {
            color: var(--c-green) !important;
        }

        [data-theme="dark"] .text-danger {
            color: var(--c-red) !important;
        }

        [data-theme="dark"] .text-warning {
            color: var(--c-yellow) !important;
        }

        [data-theme="dark"] .text-info {
            color: var(--c-blue) !important;
        }

        [data-theme="dark"] .text-muted {
            color: var(--text2) !important;
        }

        [data-theme="dark"] .text-secondary {
            color: var(--text2) !important;
        }

        /* Bootstrap contextual bg + opacity in dark mode */
        [data-theme="dark"] .bg-success,
        [data-theme="dark"] .bg-success.bg-opacity-5,
        [data-theme="dark"] .bg-success.bg-opacity-10,
        [data-theme="dark"] .bg-success.bg-opacity-15,
        [data-theme="dark"] .bg-success.bg-opacity-25 {
            background-color: var(--c-green-bg) !important;
        }

        [data-theme="dark"] .bg-danger,
        [data-theme="dark"] .bg-danger.bg-opacity-5,
        [data-theme="dark"] .bg-danger.bg-opacity-10,
        [data-theme="dark"] .bg-danger.bg-opacity-15,
        [data-theme="dark"] .bg-danger.bg-opacity-25 {
            background-color: var(--c-red-bg) !important;
        }

        [data-theme="dark"] .bg-warning,
        [data-theme="dark"] .bg-warning.bg-opacity-5,
        [data-theme="dark"] .bg-warning.bg-opacity-10,
        [data-theme="dark"] .bg-warning.bg-opacity-15,
        [data-theme="dark"] .bg-warning.bg-opacity-25 {
            background-color: var(--c-yellow-bg) !important;
        }

        [data-theme="dark"] .bg-info,
        [data-theme="dark"] .bg-info.bg-opacity-10 {
            background-color: var(--c-blue-bg) !important;
        }

        [data-theme="dark"] .bg-secondary {
            background-color: var(--surface2) !important;
        }

        /* Bootstrap contextual badges */
        [data-theme="dark"] .badge.bg-success {
            color: var(--c-green) !important;
        }

        [data-theme="dark"] .badge.bg-danger {
            color: var(--c-red) !important;
        }

        [data-theme="dark"] .badge.bg-warning {
            color: var(--c-yellow) !important;
        }

        [data-theme="dark"] .badge.bg-info {
            color: var(--c-blue) !important;
        }

        [data-theme="dark"] .badge.bg-secondary {
            color: var(--text2) !important;
            border: 1px solid var(--border);
        }

        [data-theme="dark"] .badge.bg-primary {
            color: #fff;
        }

        /* Bootstrap contextual border in dark mode */
        [data-theme="dark"] .border-success {
            border-color: var(--c-green) !important;
        }

        [data-theme="dark"] .border-danger {
            border-color: var(--c-red) !important;
        }

        [data-theme="dark"] .border-warning {
            border-color: var(--c-yellow) !important;
        }

        /* Progress bars need solid fills, not semi-transparent */
        [data-theme="dark"] .progress-bar.bg-success {
            background-color: var(--c-green) !important;
        }

        [data-theme="dark"] .progress-bar.bg-warning {
            background-color: var(--c-yellow) !important;
        }

        [data-theme="dark"] .progress-bar.bg-danger {
            background-color: var(--c-red) !important;
        }

        [data-theme="dark"] .progress-bar.bg-info {
            background-color: var(--c-blue) !important;
        }

        [data-theme="dark"] .table-light {
            --bs-table-bg: var(--surface2) !important;
            color: var(--text) !important;
        }

        /* ── Input group ─────────────────────────────────────────────── */
        .input-group-text {
            background: var(--surface2);
            border-color: var(--border);
            color: var(--text3);
        }

        /* ── Nav tabs ───────────────────────────────────────────────── */
        .nav-tabs {
            border-color: var(--border);
        }

        .nav-tabs .nav-link {
            color: var(--text2);
            border-radius: 6px 6px 0 0;
            font-size: .77rem;
            padding: 7px 14px;
            font-weight: 500;
            border: 1px solid transparent;
        }

        .nav-tabs .nav-link:hover {
            color: var(--text);
            border-color: var(--border) var(--border) transparent;
        }

        .nav-tabs .nav-link.active {
            background: var(--surface);
            border-color: var(--border) var(--border) var(--surface);
            color: var(--primary);
            font-weight: 600;
        }

        /* ── Empty state ────────────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text3);
        }

        .empty-state i {
            font-size: 2.2rem;
            margin-bottom: 10px;
            display: block;
        }

        .empty-state p {
            font-size: .79rem;
            margin: 4px 0 0;
            color: var(--text3);
        }

        /* ── Semantic color utilities ───────────────────────────────── */
        .c-green {
            color: var(--c-green) !important;
        }

        .c-yellow {
            color: var(--c-yellow) !important;
        }

        .c-red {
            color: var(--c-red) !important;
        }

        .c-blue {
            color: var(--c-blue) !important;
        }

        .c-purple {
            color: var(--c-purple) !important;
        }

        .c-slate {
            color: var(--c-slate) !important;
        }

        .c-rose {
            color: var(--c-rose, #F43F5E) !important;
        }

        /* ── Scrollbar ──────────────────────────────────────────────── */
        ::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text3);
        }

        /* ── Skeleton ───────────────────────────────────────────────── */
        .skeleton {
            background: linear-gradient(90deg, var(--surface2) 25%, var(--border) 50%, var(--surface2) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.2s infinite;
            border-radius: 4px;
        }

        @keyframes shimmer {
            from {
                background-position: 200% 0;
            }

            to {
                background-position: -200% 0;
            }
        }

        /* ── Mobile ─────────────────────────────────────────────────── */
        @media (max-width: 991.98px) {
            #sidebar {
                transform: translateX(-100%);
                width: var(--sidebar-w) !important;
            }

            #sidebar.open {
                transform: translateX(0);
            }

            #topbar,
            #topbar.collapsed {
                left: 0;
            }

            #main,
            #main.collapsed {
                margin-left: 0;
                padding: 14px;
            }

            #qvDrawer {
                width: 100%;
                right: -100%;
            }

            #sidebarOverlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, .4);
                z-index: 1039;
            }

            #sidebar.open~#sidebarOverlay {
                display: block;
            }
        }

        @media (max-width: 575.98px) {
            .kpi-val {
                font-size: 1.25rem;
            }

            .tb-kbd {
                display: none;
            }
        }
    </style>
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
            <div class="sb-section">Menu</div>
            <a href="{{ route('dashboard') }}" class="sb-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                title="Dashboard" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-speedometer2"></i><span class="sb-lbl">Dashboard</span>
            </a>
            <a href="{{ route('clients.index') }}" class="sb-link {{ request()->routeIs('clients.*') ? 'active' : '' }}"
                title="Clients" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-people"></i><span class="sb-lbl">Clients</span>
            </a>
            @can('view payments')
            <a href="{{ route('payments.index') }}" class="sb-link {{ request()->routeIs('payments.*') ? 'active' : '' }}"
                title="Payments" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-cash-coin"></i><span class="sb-lbl">Payments</span>
            </a>
            @endcan

            <a href="{{ route('meetings.all') }}"
                class="sb-link {{ request()->routeIs('meetings.all') ? 'active' : '' }}" title="All Meetings"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-calendar-event"></i><span class="sb-lbl">Meetings</span>
            </a>
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
            <a href="{{ route('requests.index') }}" class="sb-link {{ request()->routeIs('requests.*') ? 'active' : '' }}"
                title="Requests" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-inbox"></i><span class="sb-lbl">Requests</span>
            </a>
            <a href="{{ route('reviews.index') }}" class="sb-link {{ request()->routeIs('reviews.*') ? 'active' : '' }}"
                title="Reviews & Reports" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-chat-square-text"></i><span class="sb-lbl">Reviews & Reports</span>
            </a>

            <div class="sb-section">Operations</div>
            <a href="{{ route('import.index') }}" class="sb-link {{ request()->routeIs('import.*') ? 'active' : '' }}"
                title="Import Data" data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-upload"></i><span class="sb-lbl">Import Data</span>
            </a>
            <a href="#" id="exportSidebarBtn" class="sb-link" title="Export Data" data-bs-toggle="tooltip"
                data-bs-placement="right">
                <i class="bi bi-download"></i><span class="sb-lbl">Export Data</span>
            </a>
            <a href="{{ route('workflow.index') }}"
                class="sb-link {{ request()->routeIs('workflow.*') ? 'active' : '' }}" title="Workflow Stages"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-diagram-3"></i><span class="sb-lbl">Workflow Stages</span>
            </a>
            @can('view file-manager')
                <a href="{{ route('file-manager.index') }}"
                    class="sb-link {{ request()->routeIs('file-manager.*') ? 'active' : '' }}" title="File Manager"
                    data-bs-toggle="tooltip" data-bs-placement="right">
                    <i class="bi bi-folder2-open"></i><span class="sb-lbl">File Manager</span>
                </a>
            @endcan

            @canany(['manage categories', 'manage users'])
                <div class="sb-section">Management</div>
            @endcanany
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

            @hasanyrole('Super Admin|Manager')
            <div class="sb-section">Admin</div>
            <a href="{{ route('pending-changes.index') }}"
                class="sb-link {{ request()->routeIs('pending-changes.*') ? 'active' : '' }}" title="Pending Changes"
                data-bs-toggle="tooltip" data-bs-placement="right">
                <i class="bi bi-hourglass-split"></i><span class="sb-lbl">Pending Changes</span>
            </a>
            @endhasanyrole
            @role('Super Admin')
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

        <div class="tb-search">
            <div class="tb-search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="globalSearch" placeholder="Search clients, DFID, brand…" autocomplete="off">
                <span class="tb-kbd d-none d-sm-inline">/</span>
            </div>
            <div id="searchDropdown"></div>
        </div>

        <div class="ms-auto d-flex align-items-center gap-2">
            <button id="darkToggle" class="btn btn-sm d-flex align-items-center justify-content-center"
                style="width:35px;height:35px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;color:var(--text2);padding:0"
                title="Toggle theme">
                <i id="darkIcon" class="bi bi-moon-stars" style="font-size:.88rem"></i>
            </button>

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

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <script>
        $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' } });

        // ── Global "unauthorized" handler ───────────────────────────────────
        // Catches every 403 from any AJAX call app-wide, even ones whose own
        // .fail() handler doesn't specifically account for it — a consistent,
        // friendly message instead of a silent failure or raw error.
        $(document).ajaxError(function (event, xhr) {
            if (xhr.status === 403) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unauthorized',
                    text: xhr.responseJSON?.message || "You don't have permission to do this.",
                });
            }
        });

        // ── Dark mode ──────────────────────────────────────────────────────
        function updateDarkIcon() {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.getElementById('darkIcon').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
        }
        updateDarkIcon();
        document.getElementById('darkToggle').addEventListener('click', function () {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
            localStorage.setItem('dfcp_theme', dark ? 'light' : 'dark');
            updateDarkIcon();
        });

        // ── Notifications ──────────────────────────────────────────────────
        function loadNotifications() {
            $.get('{{ route("notifications.index") }}').done(function (r) {
                $('#notifBadge').toggleClass('d-none', r.unread_count === 0).text(r.unread_count);
                if (!r.notifications.length) {
                    $('#notifList').html('<div class="text-center py-4 text-muted" style="font-size:.78rem">No notifications yet.</div>');
                    return;
                }
                var html = '';
                r.notifications.forEach(function (n) {
                    html += '<a href="' + n.url + '" class="d-block px-3 py-2 notif-item" data-id="' + n.id + '" '
                        + 'style="text-decoration:none;border-bottom:1px solid var(--border);' + (n.read ? '' : 'background:rgba(var(--primary-rgb),.05)') + '">'
                        + '<div style="font-size:.78rem;font-weight:600;color:var(--text)">' + n.title + '</div>'
                        + '<div style="font-size:.72rem;color:var(--text2)">' + n.message + '</div>'
                        + '<div style="font-size:.66rem;color:var(--text3)" class="mt-1">' + n.created_at + '</div>'
                        + '</a>';
                });
                $('#notifList').html(html);
            });
        }
        loadNotifications();
        setInterval(loadNotifications, 60000);

        $(document).on('click', '.notif-item', function () {
            $.post('{{ url("notifications") }}/' + $(this).data('id') + '/read');
        });

        $('#notifMarkAll').on('click', function (e) {
            e.stopPropagation();
            $.post('{{ route("notifications.read-all") }}').done(loadNotifications);
        });

        // ── Sidebar collapse ───────────────────────────────────────────────
        const $sb = $('#sidebar'), $tb = $('#topbar'), $mn = $('#main');
        const isMobile = () => $(window).width() < 992;

        function applyCollapsed(collapsed, animate) {
            if (!animate) { $sb.css('transition', 'none'); $tb.css('transition', 'none'); $mn.css('transition', 'none'); }
            collapsed ? $sb.addClass('collapsed') : $sb.removeClass('collapsed');
            collapsed ? $tb.addClass('collapsed') : $tb.removeClass('collapsed');
            collapsed ? $mn.addClass('collapsed') : $mn.removeClass('collapsed');
            $sb.find('[data-bs-toggle="tooltip"]').each(function () {
                var inst = bootstrap.Tooltip.getInstance(this);
                if (collapsed) { if (!inst) bootstrap.Tooltip.getOrCreateInstance(this, { trigger: 'hover' }); }
                else { inst?.dispose(); }
            });
            if (!animate) requestAnimationFrame(() => { $sb.css('transition', ''); $tb.css('transition', ''); $mn.css('transition', ''); });
        }

        if (!isMobile()) applyCollapsed(localStorage.getItem('sidebar_collapsed') === '1', false);

        $('#sidebarToggle').on('click', function () {
            if (isMobile()) { $sb.toggleClass('open'); }
            else {
                const now = !$sb.hasClass('collapsed');
                applyCollapsed(now, true);
                localStorage.setItem('sidebar_collapsed', now ? '1' : '0');
            }
        });
        $('#sidebarOverlay').on('click', () => $sb.removeClass('open'));
        $(window).on('resize', function () {
            if (isMobile()) { $tb.removeClass('collapsed'); $mn.removeClass('collapsed'); }
            else { $sb.removeClass('open'); applyCollapsed(localStorage.getItem('sidebar_collapsed') === '1', false); }
        });

        // ── Quick View Drawer ──────────────────────────────────────────────
        const spColors = { Running: 'spill-running', Warning: 'spill-warning', Completed: 'spill-completed', Hold: 'spill-hold', Cancelled: 'spill-cancelled', Terminated: 'spill-terminated' };

        function openDrawer(clientId) {
            $('#qvDrawer').addClass('open');
            $('#qvBackdrop').addClass('show');
            $('#qvBody').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--text3)"></div></div>');
            $('#qvName').text('Loading…');
            $('#qvDfid, #qvStatus').html('');
            $('#qvAvatar').text('…');

            $.get('/clients/' + clientId + '/quick-view').done(function (d) {
                var initials = d.name.split(' ').slice(0, 2).map(w => w[0] || '').join('').toUpperCase();
                $('#qvAvatar').text(initials);
                $('#qvName').text(d.name);
                $('#qvDfid').text(d.dfid);
                $('#qvStatus').html('<span class="spill ' + (spColors[d.status] || 'spill-hold') + '">' + d.status + '</span>');
                $('#qvEditLink').attr('href', d.edit_url);

                var acts = '';
                if (d.activities && d.activities.length) {
                    acts = '<div class="tl">';
                    d.activities.forEach(function (a) {
                        acts += '<div class="tl-item"><div class="tl-dot done"></div>'
                            + '<div style="font-size:.75rem;color:var(--text)">' + $('<span>').text(a.action).html() + '</div>'
                            + '<div style="font-size:.67rem;color:var(--text3)">' + $('<span>').text(a.user).html() + ' · ' + a.time + '</div>'
                            + '</div>';
                    });
                    acts += '</div>';
                } else {
                    acts = '<div style="font-size:.75rem;color:var(--text3)">No activity yet.</div>';
                }

                var progressBar = '<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:5px">'
                    + '<div class="progress-bar" style="width:' + d.progress + '%;background:var(--primary)"></div>'
                    + '</div><span style="font-size:.72rem;color:var(--text2);white-space:nowrap">' + d.progress + '% (' + d.done_stages + '/' + d.total_stages + ')</span></div>';

                var html = '<div class="qv-sec">'
                    + '<div class="qv-sec-title">Info</div>'
                    + '<div class="qv-info-grid">'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Category</div><div class="qv-info-val">' + $('<span>').text(d.category || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Joined</div><div class="qv-info-val">' + $('<span>').text(d.joined || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Assigned To</div><div class="qv-info-val">' + $('<span>').text(d.assigned || '—').html() + '</div></div>'
                    + '<div class="qv-info-box"><div class="qv-info-lbl">Doc Status</div><div class="qv-info-val">' + $('<span>').text(d.doc_status || '—').html() + '</div></div>'
                    + '</div></div>'

                    + '<div class="qv-sec"><div class="qv-sec-title">Workflow Progress</div>' + progressBar + '</div>'

                    + (d.latest_update ? '<div class="qv-sec"><div class="qv-sec-title">Latest Product Update</div>'
                        + '<div class="qv-info-box"><div class="qv-info-val">' + $('<span>').text(d.latest_update.status).html() + '</div>'
                        + '<div class="qv-info-lbl">' + d.latest_update.time + '</div></div></div>' : '')

                    + (d.website ? '<div class="qv-sec"><div class="qv-sec-title">Website</div>'
                        + '<a href="' + $('<span>').text(d.website_url).html() + '" target="_blank" style="font-size:.78rem;color:var(--primary)" class="text-truncate d-block">' + $('<span>').text(d.website).html() + '</a></div>' : '')
                    + (d.designs_link ? '<div class="qv-sec"><div class="qv-sec-title">Designs</div>'
                        + '<a href="' + $('<span>').text(d.designs_link_url).html() + '" target="_blank" style="font-size:.78rem;color:var(--primary)" class="text-truncate d-block"><i class="bi bi-palette me-1"></i>' + $('<span>').text(d.designs_link).html() + '</a></div>' : '')

                    + '<div class="qv-sec"><div class="qv-sec-title">Recent Activity</div>' + acts + '</div>'

                    + '<div class="d-flex gap-2 flex-wrap mt-2">'
                    + '<a href="' + d.show_url + '" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Full Profile</a>'
                    + '<a href="' + d.edit_url + '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>'
                    + '</div>';

                $('#qvBody').html(html);
            }).fail(function () {
                $('#qvBody').html('<div class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load.</div>');
            });
        }

        function closeDrawer() {
            $('#qvDrawer').removeClass('open');
            $('#qvBackdrop').removeClass('show');
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeDrawer();
        });

        // ── Bulk bar ───────────────────────────────────────────────────────
        function updateBulkBar() {
            var cnt = $('.row-check:checked').length;
            $('#bulkCount').text(cnt);
            cnt > 0 ? $('#bulkBar').addClass('show') : $('#bulkBar').removeClass('show');
        }
        $(document).on('change', '#selectAll', function () {
            $('.row-check').prop('checked', $(this).is(':checked'));
            updateBulkBar();
        });
        $(document).on('change', '.row-check', updateBulkBar);

        $('#bulkDeleteBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            Swal.fire({ title: 'Delete ' + ids.length + ' clients?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
                .then(r => {
                    if (r.isConfirmed) {
                        $.post('{{ route("clients.bulk-delete") }}', { ids })
                            .done(res => { Swal.fire('Deleted', res.message, 'success'); if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload(); });
                    }
                });
        });

        $('#bulkTerminateBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            Swal.fire({
                title: 'Terminate ' + ids.length + ' clients?',
                text: 'This will permanently lock the workflow for these clients — no further stage progress will be possible.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Terminate'
            }).then(r => {
                if (r.isConfirmed) {
                    $.post('{{ route("clients.bulk-terminate") }}', { ids })
                        .done(res => { Swal.fire('Terminated', res.message, 'success'); if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload(); })
                        .fail(xhr => Swal.fire('Error', xhr.responseJSON?.message || 'Could not terminate clients.', 'error'));
                }
            });
        });

        $('#bulkAssignBtn').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            if (!ids.length) return;
            if (!$('#bulkAssignOwner').data('populated')) {
                var opts = $('#filterUser option').filter(function () { return this.value && this.value !== 'none'; })
                    .map(function () { return this.outerHTML; }).get().join('');
                $('#bulkAssignOwner').html('<option value="">Select staff…</option>' + opts).data('populated', true);
            }
            $('#bulkAssignOwner').val('');
            $('#bulkAssignNote').val('');
            $('#bulkAssignCount').text(ids.length);
            new bootstrap.Modal('#bulkAssignModal').show();
        });

        $('#bulkAssignConfirm').on('click', function () {
            var ids = $('.row-check:checked').map((i, el) => el.value).get();
            var ownerId = $('#bulkAssignOwner').val();
            if (!ownerId) { Swal.fire('Select a staff member', '', 'warning'); return; }
            $.post('{{ route("clients.bulk-assign") }}', { ids: ids, new_owner_id: ownerId, note: $('#bulkAssignNote').val() })
                .done(function (res) {
                    bootstrap.Modal.getInstance('#bulkAssignModal').hide();
                    Swal.fire('Assigned', res.message, 'success');
                    if (window.dfTable || window.table) (window.dfTable || window.table).ajax.reload();
                    $('#selectAll').prop('checked', false).trigger('change');
                })
                .fail(function (xhr) { Swal.fire('Error', xhr.responseJSON?.message || 'Failed to assign clients.', 'error'); });
        });

        // ── Export modal ───────────────────────────────────────────────────
        $('#exportSidebarBtn, #exportNavBtn').on('click', function (e) { e.preventDefault(); new bootstrap.Modal('#exportModal').show(); });
        $('#doExport').on('click', function () {
            var fmt = $('input[name="exportFmt"]:checked').val();
            var search = window._dtSearch || '';
            var status = window._dtStatus || '';
            var cat = window._dtCat || '';
            window.location = '{{ route("export.clients") }}?format=' + fmt + '&search=' + encodeURIComponent(search) + '&status=' + encodeURIComponent(status) + '&category_id=' + cat;
            bootstrap.Modal.getInstance('#exportModal')?.hide();
        });

        // ── Global search ──────────────────────────────────────────────────
        let searchTimer;
        const $gsInput = $('#globalSearch');
        const $gsDrop = $('#searchDropdown');

        $gsInput.on('input', function () {
            clearTimeout(searchTimer);
            var q = $.trim($(this).val());
            if (q.length < 2) { $gsDrop.hide(); return; }
            searchTimer = setTimeout(function () {
                $.get('{{ route("search.global") }}', { q: q }).done(function (results) {
                    if (!results.length) {
                        $gsDrop.html('<div class="px-3 py-3 text-center" style="font-size:.77rem;color:var(--text3)">No results</div>').show();
                        return;
                    }
                    var html = '';
                    results.forEach(function (r) {
                        var sc = spColors[r.client_status] || 'spill-hold';
                        html += '<a href="' + r.url + '" class="sr-item">'
                            + '<div class="flex-fill min-w-0">'
                            + '<div style="font-size:.77rem;font-weight:600;color:var(--text)" class="text-truncate">' + $('<span>').text(r.client_name).html() + '</div>'
                            + '<div style="font-size:.68rem;color:var(--text3)">' + $('<span>').text(r.dfid_number).html()
                            + (r.brand_name ? ' · ' + $('<span>').text(r.brand_name).html() : '') + '</div>'
                            + '</div><span class="spill ' + sc + '">' + r.client_status + '</span></a>';
                    });
                    html += '<a href="{{ route("clients.index") }}?search=' + encodeURIComponent(q) + '" style="display:block;text-align:center;padding:9px;font-size:.73rem;color:var(--primary);border-top:1px solid var(--border)">View all results →</a>';
                    $gsDrop.html(html).show();
                });
            }, 280);
        });

        $gsInput.on('keydown', function (e) {
            if (e.key === 'Enter') { var q = $.trim($(this).val()); if (q) window.location = '{{ route("clients.index") }}?search=' + encodeURIComponent(q); }
            if (e.key === 'Escape') { $gsDrop.hide(); $(this).blur(); }
        });
        $(document).on('click', function (e) { if (!$(e.target).closest('.tb-search').length) $gsDrop.hide(); });
        $gsInput.on('focus', function () { if ($gsDrop.children().length) $gsDrop.show(); });

        // ── Keyboard shortcuts ─────────────────────────────────────────────
        var gPressed = false, gTimer;
        document.addEventListener('keydown', function (e) {
            var tag = document.activeElement.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || document.activeElement.isContentEditable) return;
            if (e.key === '/') { e.preventDefault(); document.getElementById('globalSearch').focus(); return; }
            if (e.key === 'Escape') { document.getElementById('globalSearch').blur(); $gsDrop.hide(); closeDrawer(); return; }
            if (e.key.toLowerCase() === 'g') { gPressed = true; clearTimeout(gTimer); gTimer = setTimeout(function () { gPressed = false; }, 1000); return; }
            if (gPressed) {
                if (e.key.toLowerCase() === 'd') window.location = '{{ route("dashboard") }}';
                if (e.key.toLowerCase() === 'c') window.location = '{{ route("clients.index") }}';
                gPressed = false;
            }
        });

        // Auto-dismiss success alerts
        setTimeout(function () { $('.alert-success').fadeOut(400, function () { $(this).remove(); }); }, 4000);

        // ── Chart.js theme helper ──────────────────────────────────────────
        window.chartTheme = function () {
            var dark = document.documentElement.getAttribute('data-theme') === 'dark';
            return {
                gridColor: dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.05)',
                textColor: dark ? '#94A3B8' : '#94a3b8',
                borderColor: dark ? 'rgba(255,255,255,.08)' : '#e2e8f0',
                colors: dark
                    ? ['#3B82F6', '#22C55E', '#F59E0B', '#EF4444', '#A78BFA', '#06B6D4', '#F43F5E', '#FB923C', '#34D399', '#60A5FA']
                    : ['#2563eb', '#059669', '#d97706', '#dc2626', '#7c3aed', '#0891b2', '#e11d48', '#ea580c', '#10b981', '#3b82f6'],
            };
        };
        // Re-render charts when theme toggles
        document.getElementById('darkToggle').addEventListener('click', function () {
            if (window._charts) window._charts.forEach(function (c) { try { c.update(); } catch (e) { } });
        });
    </script>
    @stack('scripts')
</body>

</html>