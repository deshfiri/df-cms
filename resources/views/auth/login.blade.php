<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — {{ \App\Models\Setting::get('app_name', 'DFCP COMS') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- No-FOUC dark mode --}}
    <script>
    (function(){
        var t=localStorage.getItem('dfcp_theme');
        if(t==='dark'||(t===null&&window.matchMedia('(prefers-color-scheme:dark)').matches)){
            document.documentElement.setAttribute('data-theme','dark');
        }
    })();
    </script>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    @php
        $themeHex   = ltrim(\App\Models\Setting::get('theme_color', '#1F3C88'), '#');
        $themeColor = '#' . $themeHex;
        $tR = hexdec(substr($themeHex,0,2));
        $tG = hexdec(substr($themeHex,2,2));
        $tB = hexdec(substr($themeHex,4,2));
        $themeColorDark = sprintf('#%02x%02x%02x', max(0,(int)round($tR*.82)), max(0,(int)round($tG*.82)), max(0,(int)round($tB*.82)));
    @endphp

    <style>
    :root {
        --primary:      {{ $themeColor }};
        --primary-dark: {{ $themeColorDark }};
        --primary-rgb:  {{ $tR }}, {{ $tG }}, {{ $tB }};
        --bg:       #f1f5f9;
        --surface:  #ffffff;
        --surface2: #f8fafc;
        --border:   #e2e8f0;
        --text:     #0f172a;
        --text2:    #475569;
        --text3:    #94a3b8;
    }
    [data-theme="dark"] {
        --bg:      #0d1117;
        --surface: #161b22;
        --surface2:#0d1117;
        --border:  #30363d;
        --text:    #e6edf3;
        --text2:   #8b949e;
        --text3:   #6e7681;
        color-scheme: dark;
    }

    *, *::before, *::after { box-sizing: border-box; }
    html { font-size: 14px; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: var(--bg);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        -webkit-font-smoothing: antialiased;
        padding: 16px;
    }

    .login-wrap {
        width: 100%;
        max-width: 420px;
    }

    .login-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0,0,0,.12);
        padding: 36px 36px 28px;
    }

    .brand-icon {
        width: 52px; height: 52px; border-radius: 13px;
        background: rgba(var(--primary-rgb),.12);
        color: var(--primary);
        font-size: 1.4rem;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 12px;
    }
    .brand-name {
        font-size: 1.15rem; font-weight: 700;
        color: var(--text); letter-spacing: -.03em;
    }
    .brand-sub {
        font-size: .73rem; color: var(--text3); margin-top: 2px;
    }

    .form-label { font-size: .74rem; font-weight: 500; color: var(--text2); margin-bottom: 5px; }
    .form-control {
        background: var(--surface2); border: 1px solid var(--border);
        color: var(--text); border-radius: 8px; font-size: .82rem;
        font-family: inherit; height: 38px; padding: 0 12px;
        transition: border-color .15s, box-shadow .15s;
    }
    .form-control::placeholder { color: var(--text3); }
    .form-control:focus {
        outline: none; background: var(--surface);
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(var(--primary-rgb),.12);
        color: var(--text);
    }
    .form-control.is-invalid { border-color: #dc2626; }
    .invalid-feedback { font-size: .71rem; color: #dc2626; margin-top: 3px; }

    .input-group .form-control { border-radius: 0 8px 8px 0 !important; }
    .input-group-text {
        background: var(--surface2); border: 1px solid var(--border);
        border-right: none; border-radius: 8px 0 0 8px;
        color: var(--text3); padding: 0 11px;
    }
    .input-group .form-control.is-invalid ~ .invalid-feedback { display: block; }

    .alert-danger {
        background: rgba(220,38,38,.09);
        border: 1px solid rgba(220,38,38,.2);
        color: #ef4444;
        border-radius: 8px; font-size: .78rem; padding: 10px 14px;
    }
    [data-theme="dark"] .alert-danger {
        background: rgba(248,113,113,.1);
        color: #f87171;
    }

    .form-check-input { width: 14px; height: 14px; cursor: pointer; border-color: var(--border); }
    .form-check-label { font-size: .75rem; color: var(--text2); cursor: pointer; }

    .btn-login {
        background: var(--primary); border: none; color: #fff;
        border-radius: 8px; width: 100%; height: 40px;
        font-size: .83rem; font-weight: 600; cursor: pointer;
        transition: background .15s, box-shadow .15s;
        font-family: inherit;
        display: flex; align-items: center; justify-content: center; gap: 7px;
    }
    .btn-login:hover {
        background: var(--primary-dark);
        box-shadow: 0 4px 12px rgba(var(--primary-rgb),.3);
    }

    .login-footer { font-size: .7rem; color: var(--text3); text-align: center; margin-top: 20px; }
    .login-footer i { margin-right: 4px; }

    .theme-toggle {
        position: fixed; top: 16px; right: 16px;
        width: 32px; height: 32px;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 8px; color: var(--text2);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; font-size: .85rem;
        transition: background .15s, color .15s;
    }
    .theme-toggle:hover { background: var(--surface2); color: var(--text); }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" title="Toggle dark mode">
    <i id="themeIcon" class="bi bi-moon-stars"></i>
</button>

<div class="login-wrap">
    <div class="login-card">
        <div class="text-center mb-5">
            <div class="brand-icon"><i class="bi bi-shop"></i></div>
            <div class="brand-name">{{ \App\Models\Setting::get('app_name', 'DFCP COMS') }}</div>
            <div class="brand-sub">Client Operations Management System</div>
        </div>

        @if($errors->any())
        <div class="alert-danger d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
            <span>{{ $errors->first() }}</span>
        </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus
                           class="form-control @error('email') is-invalid @enderror"
                           placeholder="you@company.com">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="pwInput" required
                           class="form-control @error('password') is-invalid @enderror"
                           placeholder="••••••••">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i>Sign In
            </button>
        </form>

        <div class="login-footer">
            <i class="bi bi-shield-check"></i>Secured · DFCP Commerce System
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateThemeIcon() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.getElementById('themeIcon').className = 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars');
}
updateThemeIcon();
document.getElementById('themeToggle').addEventListener('click', function() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
    localStorage.setItem('dfcp_theme', dark ? 'light' : 'dark');
    updateThemeIcon();
});
</script>
</body>
</html>
