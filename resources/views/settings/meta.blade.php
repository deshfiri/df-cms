@extends('layouts.app')
@section('title', 'Meta Marketing Integration')

@push('styles')
<style>
    .mt-state {
        display: flex; align-items: center; gap: .7rem;
        padding: .8rem 1rem; border-radius: var(--radius);
        border: 1px solid var(--border); background: var(--surface2);
    }
    .mt-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .mt-dot.on { background: #16a34a; }
    .mt-dot.off { background: #dc3545; }
    .mt-title { font-size: .88rem; font-weight: 700; color: var(--text); }
    .mt-sub { font-size: .74rem; color: var(--text3); }
    .mt-copy {
        font-family: Menlo, Consolas, monospace; font-size: .74rem;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: 6px; padding: .35rem .5rem; color: var(--text2); word-break: break-all;
    }
    .mt-step { font-size: .78rem; color: var(--text2); }
    .mt-step li { margin-bottom: .3rem; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="page-title mb-0"><i class="bi bi-meta me-2"></i>Meta Marketing Integration</h4>
    <a href="{{ route('settings.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>General Settings
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success py-2" style="font-size:.84rem">{{ session('success') }}</div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">App credentials</h6>
                <small class="text-muted">
                    These identify this application to Meta and are shared by every brand.
                </small>
            </div>
            <div class="card-body">
                <div class="mt-state mb-3">
                    <span class="mt-dot {{ $settings->isConfigured() ? 'on' : 'off' }}"></span>
                    <div class="flex-grow-1">
                        @if($settings->isConfigured())
                            <div class="mt-title">Configured</div>
                            <div class="mt-sub">
                                Brands can now be connected from
                                <a href="{{ route('marketing.index') }}" style="color:var(--primary)">Marketing</a>.
                                {{ $connected }} brand(s) currently connected.
                            </div>
                        @else
                            <div class="mt-title">Not configured</div>
                            <div class="mt-sub">Enter the app ID and secret below before connecting any brand.</div>
                        @endif
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.meta.update') }}">
                @csrf

                <label class="form-label small fw-semibold">App ID</label>
                <input type="text" name="app_id" class="form-control form-control-sm mb-3 @error('app_id') is-invalid @enderror"
                       value="{{ old('app_id', $settings->appId()) }}" placeholder="e.g. 1234567890123456" autocomplete="off">
                @error('app_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                <label class="form-label small fw-semibold">App Secret</label>
                <input type="password" name="app_secret" class="form-control form-control-sm mb-1"
                       placeholder="{{ $settings->appSecret() ? '•••••••• (leave blank to keep)' : 'From Meta App → Settings → Basic' }}"
                       autocomplete="new-password">
                <div class="mt-sub mb-3">Stored encrypted. Leave blank to keep the existing secret.</div>

                <label class="form-label small fw-semibold">Authorised redirect URI</label>
                <div class="mt-copy mb-1">{{ $redirectUri }}</div>
                <div class="mt-sub mb-3">
                    Paste this into your Meta app under <strong>Facebook Login → Settings → Valid OAuth Redirect URIs</strong>,
                    exactly as shown including the port.
                </div>

                <div class="row g-2">
                    <div class="col-sm-4">
                        <label class="form-label small fw-semibold">API version</label>
                        <input type="text" name="api_version" class="form-control form-control-sm @error('api_version') is-invalid @enderror"
                               value="{{ old('api_version', $settings->apiVersion()) }}" placeholder="v21.0">
                        @error('api_version')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label small fw-semibold">Scopes</label>
                        <input type="text" name="scopes" class="form-control form-control-sm"
                               value="{{ old('scopes', implode(',', $settings->scopes())) }}">
                    </div>
                </div>
                <div class="mt-sub mt-1 mb-3">
                    Read-only by default. Add <code>ads_management</code> only if this app should
                    create or pause campaigns rather than just report on them.
                </div>

                @if($connected > 0)
                    <div class="alert alert-warning py-2" style="font-size:.78rem">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Changing the app secret invalidates the {{ $connected }} existing brand connection(s).
                        Each brand would need reconnecting.
                    </div>
                @endif

                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save settings</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3"><h6 class="fw-bold mb-0">Setting up the Meta app</h6></div>
            <div class="card-body">
                <ol class="mt-step ps-3 mb-3">
                    <li>Open <strong>developers.facebook.com</strong> → My Apps → Create App.</li>
                    <li>Choose the <strong>Business</strong> type.</li>
                    <li>Add the <strong>Facebook Login</strong> product, and paste the redirect URI on the left.</li>
                    <li>Add the <strong>Marketing API</strong> product.</li>
                    <li>Copy the App ID and App Secret from <strong>Settings → Basic</strong> into this form.</li>
                    <li>Go to <a href="{{ route('marketing.index') }}" style="color:var(--primary)">Marketing</a>,
                        open a brand, and press <strong>Connect Meta</strong>.</li>
                </ol>

                <div class="mt-sub">
                    While the app is in Development mode it only reaches ad accounts owned by
                    people with a role on the app. Reading a client's own ad account needs
                    App Review, or that account added to the app's business.
                </div>

                <hr class="my-3">

                <div class="fw-bold mb-1" style="font-size:.8rem">Where each credential lives</div>
                <div class="mt-step">
                    <strong>Here:</strong> the app ID and secret — one set, for the whole installation.<br>
                    <strong>Per brand:</strong> the access token granted when someone presses Connect Meta,
                    stored encrypted against that brand and never shown again.
                </div>

                <hr class="my-3">

                <div class="fw-bold mb-1" style="font-size:.8rem">After connecting</div>
                <div class="mt-step">
                    Data refreshes every 20 minutes. That needs the scheduler and a queue worker
                    running in production:
                    <div class="mt-copy mt-2">php artisan schedule:work<br>php artisan queue:work</div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
