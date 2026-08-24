@extends('layouts.app')
@section('title', 'WhatsApp Settings')

@push('styles')
<style>
    .wa-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .wa-label { font-size: var(--fs-sm); font-weight: 600; color: var(--text2); margin-bottom: 4px; display: block; }
    .wa-help { font-size: var(--fs-2xs); color: var(--text3); margin-top: 4px; display: block; line-height: 1.5; }
    .wa-copy {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: var(--fs-2xs);
        background: var(--surface2); border: 1px solid var(--border); color: var(--text2);
        border-radius: var(--radius-sm); padding: .45rem .6rem; word-break: break-all;
    }
    .wa-state { display: flex; align-items: center; gap: var(--space-3); }
    .wa-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .wa-dot.on { background: var(--c-green); }
    .wa-dot.off { background: var(--text3); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-whatsapp me-2"></i>WhatsApp</h4>
        <small style="color:var(--text3)">Meta app credentials for customer messaging.</small>
    </div>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'whatsapp'])

<div>
    @if(session('success'))
        <div class="wa-note mb-4" style="border-left:3px solid var(--c-green)">
            <i class="bi bi-check-circle me-1" style="color:var(--c-green)"></i>{{ session('success') }}
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.whatsapp.update') }}">
                @csrf

                <div class="card section-card mb-4">
                    <div class="card-header py-3">
                        <h6 class="fw-bold mb-0">Meta app credentials</h6>
                        <small style="color:var(--text3)">
                            From your Meta app under WhatsApp → API Setup. Separate from the Meta
                            Marketing app so a change here can never disturb ad syncing.
                        </small>
                    </div>
                    <div class="card-body">
                        <div class="wa-state mb-3">
                            <span class="wa-dot {{ $isConfigured ? 'on' : 'off' }}"></span>
                            <div>
                                <div class="fw-semibold" style="font-size:.86rem">
                                    {{ $isConfigured ? 'Configured' : 'Not configured' }}
                                </div>
                                <div style="font-size:.72rem;color:var(--text3)">
                                    @if($canOnboard)
                                        Ready to connect numbers.
                                    @elseif($isConfigured)
                                        Add the Embedded Signup configuration ID to connect numbers.
                                    @else
                                        Enter the app ID, secret and a verify token below.
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="wa-label" for="waAppId">App ID <span style="color:var(--c-red)">*</span></label>
                            <input type="text" id="waAppId" name="app_id" class="form-control font-monospace"
                                   value="{{ old('app_id', $appId) }}" required>
                            @error('app_id')<span class="wa-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="wa-label" for="waAppSecret">App Secret <span style="color:var(--c-red)">*</span></label>
                            <input type="password" id="waAppSecret" name="app_secret" class="form-control font-monospace"
                                   autocomplete="new-password"
                                   placeholder="{{ $hasAppSecret ? '•••••••• saved — leave blank to keep' : 'From App Settings → Basic' }}">
                            <span class="wa-help">
                                Stored encrypted and never shown again. Also signs every incoming webhook,
                                so the webhook stops accepting traffic if this is wrong.
                            </span>
                            @error('app_secret')<span class="wa-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="wa-label" for="waConfigId">Embedded Signup configuration ID</label>
                            <input type="text" id="waConfigId" name="config_id" class="form-control font-monospace"
                                   value="{{ old('config_id', $configId) }}">
                            <span class="wa-help">
                                From WhatsApp → Embedded Signup in your Meta app. Requires Tech Provider
                                status on the app before Meta will run the flow.
                            </span>
                        </div>

                        <div class="mb-0">
                            <label class="wa-label" for="waApiVersion">Graph API version</label>
                            <input type="text" id="waApiVersion" name="api_version" class="form-control font-monospace"
                                   value="{{ old('api_version', $apiVersion) }}" placeholder="v21.0" style="max-width:140px">
                            @error('api_version')<span class="wa-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                    </div>
                </div>

                <div class="card section-card mb-4">
                    <div class="card-header py-3">
                        <h6 class="fw-bold mb-0">Webhook</h6>
                        <small style="color:var(--text3)">Register these two values on the Meta app's WhatsApp webhook.</small>
                    </div>
                    <div class="card-body">
                        <label class="wa-label">Callback URL</label>
                        <div class="wa-copy mb-3">{{ $webhookUrl }}</div>

                        <label class="wa-label" for="waVerifyToken">Verify token</label>
                        <div class="d-flex gap-2 align-items-start">
                            <input type="password" id="waVerifyToken" name="verify_token" class="form-control font-monospace"
                                   autocomplete="new-password"
                                   placeholder="{{ $hasVerifyToken ? '•••••••• saved — leave blank to keep' : 'At least 12 characters' }}">
                            <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" id="waGenerate">
                                <i class="bi bi-shuffle me-1"></i>Generate
                            </button>
                        </div>
                        <span class="wa-help">
                            Meta echoes this back when it verifies the subscription. Generating one saves it
                            immediately and shows it once.
                        </span>
                        @error('verify_token')<span class="wa-help" style="color:var(--c-red)">{{ $message }}</span>@enderror

                        @unless(request()->secure())
                            <div class="wa-note mt-3" style="border-left:3px solid var(--c-yellow)">
                                <i class="bi bi-exclamation-triangle me-1" style="color:var(--c-yellow)"></i>
                                Meta only delivers webhooks to public HTTPS endpoints. This page is not being
                                served over HTTPS, so the callback URL above will not work as shown.
                            </div>
                        @endunless
                    </div>
                </div>

                <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>Save Settings</button>
            </form>

            @if($isConfigured)
                <form method="POST" action="{{ route('settings.whatsapp.disconnect') }}" class="mt-3"
                      onsubmit="return confirm('Remove the WhatsApp app credentials? Connected numbers keep their own tokens, but no new number can be onboarded and incoming webhooks will be rejected.')">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Remove credentials</button>
                </form>
            @endif
        </div>

        <div class="col-lg-5">
            <div class="card section-card" style="position:sticky;top:72px">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Before this works</h6>
                </div>
                <div class="card-body">
                    <ol class="mt-step" style="font-size:.78rem;color:var(--text2);padding-left:1.1rem;line-height:1.7">
                        <li>Create a Meta app with the <strong>WhatsApp</strong> product added.</li>
                        <li>Request <code>whatsapp_business_management</code> and
                            <code>whatsapp_business_messaging</code> permissions.</li>
                        <li>Paste the App ID and App Secret here and save.</li>
                        <li>Generate a verify token, then register the callback URL and that token on the
                            Meta app's WhatsApp webhook.</li>
                        <li>Subscribe the webhook to <code>messages</code>.</li>
                        <li>Connect a number under <a href="{{ route('whatsapp.inbox') }}" style="color:var(--primary)">WhatsApp</a>
                            once Embedded Signup is available on the app.</li>
                    </ol>

                    <div class="wa-note mt-3">
                        <strong>Embedded Signup needs approval.</strong> Meta only enables it for apps with
                        Tech Provider status that have passed App Review. Until then the flow will open and
                        then fail — the credentials above are still worth saving, since the webhook and the
                        API both work without it.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script>
    $('#waGenerate').on('click', function () {
        var $btn = $(this).prop('disabled', true);

        $.post('{{ route('settings.whatsapp.verify-token') }}')
            .done(function (r) {
                Swal.fire({
                    icon: 'success',
                    title: 'Verify token generated',
                    html: '<div style="font-family:monospace;word-break:break-all;font-size:.8rem">'
                        + $('<span>').text(r.token).html() + '</div>'
                        + '<p style="font-size:.78rem;margin-top:.75rem">' + $('<span>').text(r.notice).html() + '</p>',
                    confirmButtonText: 'Copied it',
                });
                $('#waVerifyToken').attr('placeholder', '•••••••• saved — leave blank to keep');
            })
            .fail(function () {
                Swal.fire('Error', 'Could not generate a token.', 'error');
            })
            .always(function () { $btn.prop('disabled', false); });
    });
</script>
@endpush
