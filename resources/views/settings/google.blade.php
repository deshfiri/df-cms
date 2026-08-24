@extends('layouts.app')
@section('title', 'Google Meet Integration')

@push('styles')
<style>
    .gi-state {
        display: flex; align-items: center; gap: .7rem;
        padding: .8rem 1rem; border-radius: var(--radius);
        border: 1px solid var(--border); background: var(--surface2);
    }
    .gi-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: var(--text3); }
    .gi-dot.on { background: #16a34a; }
    .gi-dot.off { background: #dc3545; }
    .gi-state-title { font-size: .88rem; font-weight: 700; color: var(--text); }
    .gi-state-sub { font-size: .74rem; color: var(--text3); }
    .gi-copy {
        font-family: Menlo, Consolas, monospace; font-size: .74rem;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: 6px; padding: .35rem .5rem; color: var(--text2);
        word-break: break-all;
    }
    .gi-step { font-size: .78rem; color: var(--text2); }
    .gi-step li { margin-bottom: .3rem; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-camera-video me-2"></i>Google Meet</h4>
        <small style="color:var(--text3)">Generates a Meet link when a meeting is booked.</small>
    </div>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'google'])

<div>
@if(session('success'))
    <div class="alert alert-success py-2" style="font-size:.84rem">{{ session('success') }}</div>
@endif
@error('connection')
    <div class="alert alert-danger py-2" style="font-size:.84rem">{{ $message }}</div>
@enderror

<div class="row g-4">
    <div class="col-lg-7">

        {{-- Current state, in plain words --}}
        <div class="card section-card mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Connection</h6>
                <small style="color:var(--text3)">A meeting booked while this is disconnected is still saved — it just has no Meet link.</small>
            </div>
            <div class="card-body">
                <div class="gi-state mb-3">
                    <span class="gi-dot {{ $activeMode ? 'on' : 'off' }}"></span>
                    <div class="flex-grow-1">
                        @if($activeMode === \App\Services\Google\GoogleIntegrationSettings::MODE_OAUTH)
                            <div class="gi-state-title">Connected via Google account</div>
                            <div class="gi-state-sub">{{ $settings->connectedAccount() ?: 'Account connected' }} — Meet links will be generated.</div>
                        @elseif($activeMode === \App\Services\Google\GoogleIntegrationSettings::MODE_SERVICE_ACCOUNT)
                            <div class="gi-state-title">Connected via service account</div>
                            <div class="gi-state-sub">
                                @if($settings->impersonateEmail())
                                    Impersonating {{ $settings->impersonateEmail() }}.
                                @else
                                    No impersonation email set — events will be created, but <strong>Meet links will not</strong>.
                                @endif
                            </div>
                        @else
                            <div class="gi-state-title">Not connected</div>
                            <div class="gi-state-sub">Meeting links are not being generated.</div>
                        @endif
                    </div>

                    @if($activeMode)
                        <form method="POST" action="{{ route('settings.google.test') }}" class="m-0">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-plug me-1"></i>Test</button>
                        </form>
                    @endif
                </div>

                @if($settings->isOauthConnected())
                    <form method="POST" action="{{ route('settings.google.disconnect') }}"
                          onsubmit="return confirm('Disconnect Google? New bookings will stop generating Meet links.')">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Disconnect Google account</button>
                    </form>
                @elseif($settings->hasOauthCredentials())
                    <a href="{{ route('settings.google.connect') }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-google me-1"></i>Connect Google account
                    </a>
                @else
                    <div class="gi-state-sub">Save an OAuth client ID and secret below, then come back here to connect.</div>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('settings.google.update') }}" enctype="multipart/form-data">
        @csrf

        {{-- Preferred path --}}
        <div class="card section-card mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">OAuth client <span class="badge bg-success-subtle text-success ms-1" style="font-size:.62rem">Recommended</span></h6>
                <small style="color:var(--text3)">Works with an ordinary Google account and reliably produces Meet links.</small>
            </div>
            <div class="card-body">
                <label class="form-label small fw-semibold">Client ID</label>
                <input type="text" name="client_id" class="form-control form-control-sm mb-3 @error('client_id') is-invalid @enderror"
                       value="{{ old('client_id', $settings->clientId()) }}" placeholder="…apps.googleusercontent.com" autocomplete="off">
                @error('client_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror

                <label class="form-label small fw-semibold">Client secret</label>
                <input type="password" name="client_secret" class="form-control form-control-sm mb-2"
                       placeholder="{{ $settings->clientSecret() ? '•••••••• (leave blank to keep)' : 'GOCSPX-…' }}" autocomplete="new-password">
                <div class="gi-state-sub mb-3">Stored encrypted. Leave blank to keep the existing secret.</div>

                <label class="form-label small fw-semibold">Authorised redirect URI</label>
                <div class="gi-copy mb-2">{{ $redirectUri }}</div>
                <div class="gi-state-sub">Paste this into your Google Cloud OAuth client, exactly as shown.</div>
            </div>
        </div>

        {{-- Fallback path --}}
        <div class="card section-card mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Service account <span class="text-muted" style="font-size:.7rem;font-weight:400">— manual fallback</span></h6>
                <small style="color:var(--text3)">Used only when no Google account is connected above.</small>
            </div>
            <div class="card-body">
                <label class="form-label small fw-semibold">Key file (JSON)</label>
                <input type="file" name="service_account" accept=".json,application/json"
                       class="form-control form-control-sm mb-1 @error('service_account') is-invalid @enderror">
                @error('service_account')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="gi-state-sub mb-3">
                    @if($settings->serviceAccountPath())
                        A key file is stored.
                        <label class="ms-2"><input type="checkbox" name="remove_service_account" value="1"> remove it</label>
                    @else
                        No key file stored.
                    @endif
                </div>

                <label class="form-label small fw-semibold">Impersonate (Workspace email)</label>
                <input type="email" name="impersonate_email" class="form-control form-control-sm mb-1 @error('impersonate_email') is-invalid @enderror"
                       value="{{ old('impersonate_email', $settings->impersonateEmail()) }}" placeholder="bookings@yourdomain.com">
                @error('impersonate_email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="gi-state-sub">
                    Required for Meet links on this path. A service account with nobody to impersonate creates
                    calendar events with no video link at all.
                </div>
            </div>
        </div>

        <div class="card section-card mb-4">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Calendar</h6>
            </div>
            <div class="card-body">
                <label class="form-label small fw-semibold">Calendar ID</label>
                <input type="text" name="calendar_id" class="form-control form-control-sm"
                       value="{{ old('calendar_id', $settings->calendarId()) }}" placeholder="primary">
                <div class="gi-state-sub mt-1">Use <code>primary</code> for the connected account's own calendar, or a shared calendar's ID.</div>
            </div>
        </div>

        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save settings</button>
        </form>
    </div>

    {{-- Setup instructions --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3">
                <h6 class="fw-bold mb-0">Setting up the OAuth client</h6>
            </div>
            <div class="card-body">
                <ol class="gi-step ps-3 mb-3">
                    <li>Open <strong>console.cloud.google.com</strong> and pick (or create) a project.</li>
                    <li>Enable the <strong>Google Calendar API</strong> for it.</li>
                    <li>Under <strong>APIs &amp; Services → Credentials</strong>, create an
                        <strong>OAuth client ID</strong> of type <em>Web application</em>.</li>
                    <li>Add the redirect URI shown on the left.</li>
                    <li>Copy the client ID and secret into the form, save, then press
                        <strong>Connect Google account</strong>.</li>
                </ol>

                <div class="gi-state-sub">
                    Sign in as the account the meetings should belong to. Its calendar is where events
                    are created, and its Meet links are what clients receive.
                </div>

                <hr class="my-3">

                <div class="fw-bold mb-1" style="font-size:.8rem">What happens on booking</div>
                <div class="gi-step">
                    Booking a meeting creates the calendar event, requests a Meet link, and saves both
                    against the meeting. Attendees (the assigned staff member and the client's contact
                    email) are invited by Google. Rescheduling and cancelling update the same event.
                </div>

                <hr class="my-3">

                <div class="fw-bold mb-1" style="font-size:.8rem">If the connection is down</div>
                <div class="gi-step">
                    Nothing blocks. Meetings still save, and the booking screen keeps working — the
                    meeting simply has no Meet link until this is connected.
                </div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
@endsection
