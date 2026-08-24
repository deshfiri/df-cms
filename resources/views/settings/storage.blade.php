@extends('layouts.app')
@section('title', 'Storage & CDN')

@push('styles')
<style>
    .st-status {
        display: flex; align-items: center; gap: var(--space-4); flex-wrap: wrap;
        background: var(--surface); border: 1px solid var(--border);
        border-left: 3px solid var(--primary);
        border-radius: var(--radius-md); padding: var(--space-4) var(--space-5);
        margin-bottom: var(--space-5);
    }
    .st-status-icon {
        width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%;
        display: grid; place-items: center; font-size: 1.1rem;
        background: rgba(var(--primary-rgb), .12); color: var(--primary);
    }
    .st-status-title { font-size: var(--fs-h4); font-weight: 700; color: var(--text); }
    .st-status-sub { font-size: var(--fs-xs); color: var(--text3); }

    /* ── Provider tiles ─────────────────────────────────────────── */
    .st-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: var(--space-3); margin-bottom: var(--space-5); }
    .st-tile {
        position: relative; text-align: left; width: 100%;
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius-md); padding: var(--space-4);
        cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .st-tile:hover { border-color: var(--primary); transform: translateY(-1px); box-shadow: var(--shadow-sm); }
    .st-tile.selected { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .14); }
    .st-tile-head { display: flex; align-items: center; gap: var(--space-2); margin-bottom: 6px; }
    .st-tile-icon { font-size: 1.05rem; color: var(--primary); }
    .st-tile-name { font-size: var(--fs-body); font-weight: 700; color: var(--text); }
    .st-tile-desc { font-size: var(--fs-xs); color: var(--text3); line-height: 1.45; }
    .st-tile-state { margin-top: var(--space-3); }

    /* ── Panels ─────────────────────────────────────────────────── */
    .st-panel {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius-md); overflow: hidden;
    }
    .st-panel-head { padding: var(--space-4) var(--space-5); border-bottom: 1px solid var(--border); }
    .st-panel-title { font-size: var(--fs-h4); font-weight: 700; color: var(--text); margin: 0; }
    .st-panel-sub { font-size: var(--fs-xs); color: var(--text3); }
    .st-panel-body { padding: var(--space-5); }
    .st-panel-foot {
        padding: var(--space-3) var(--space-5); border-top: 1px solid var(--border);
        background: var(--surface2); display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center;
    }

    .st-label { font-size: var(--fs-sm); font-weight: 600; color: var(--text2); margin-bottom: 4px; display: block; }
    .st-help { font-size: var(--fs-2xs); color: var(--text3); margin-top: 4px; display: block; line-height: 1.5; }
    .st-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .st-note code { color: var(--primary); background: none; padding: 0; font-size: var(--fs-2xs); }

    /* ── Test results ───────────────────────────────────────────── */
    .st-result { margin-top: var(--space-4); font-size: var(--fs-xs); display: none; }
    .st-step { display: flex; align-items: center; gap: var(--space-2); padding: 3px 0; color: var(--text2); }
    .st-step.ok { color: var(--c-green); }
    .st-step.bad { color: var(--c-red); }
    .st-result-msg { margin-top: var(--space-2); color: var(--text2); line-height: 1.6; }

    /* ── Usage ──────────────────────────────────────────────────── */
    .st-usage { display: flex; flex-wrap: wrap; gap: var(--space-2); }
    .st-usage-item {
        display: flex; align-items: baseline; gap: 6px;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: 20px; padding: 4px 12px; font-size: var(--fs-xs); color: var(--text2);
    }
    .st-usage-count { font-weight: 700; color: var(--text); }
</style>
@endpush

@section('content')
@php
    $providerLabels = [
        'local'      => 'this server',
        'cloudflare' => 'Cloudflare R2',
        'cloudinary' => 'Cloudinary',
    ];

    // Which panel opens on load. A save or a failed validation must bring back
    // the panel it came from, not the one belonging to the active provider —
    // otherwise the field errors and the Activate button are hidden behind a
    // tile the admin has to know to click again.
    $openPanel = old('provider') ?: (session('panel') ?: $provider);
@endphp

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-hdd-network me-2"></i>Storage &amp; CDN</h4>
        <small style="color:var(--text3)">Choose where uploaded files are kept and served from.</small>
    </div>
</div>

<div class="set-layout">
    @include('settings.partials.nav', ['active' => 'storage'])

    <div>
        @if(session('success'))
            <div class="st-note mb-4" style="border-left:3px solid var(--c-green)">
                <i class="bi bi-check-circle me-1" style="color:var(--c-green)"></i>{{ session('success') }}
            </div>
        @endif
        @error('storage')
            <div class="st-note mb-4" style="border-left:3px solid var(--c-red)">
                <i class="bi bi-exclamation-triangle me-1" style="color:var(--c-red)"></i>{{ $message }}
            </div>
        @enderror

        {{-- Where things stand right now --}}
        <div class="st-status">
            <div class="st-status-icon">
                <i class="bi {{ $provider === 'local' ? 'bi-hdd' : 'bi-cloud-check' }}"></i>
            </div>
            <div class="flex-grow-1">
                <div class="st-status-title">
                    New uploads go to {{ $providerLabels[$provider] }}
                </div>
                <div class="st-status-sub">
                    @if($provider === 'local')
                        Files are written to this server's private disk and streamed back through the app. Nothing else is required — connect a provider below only if you want the storage and bandwidth moved off this machine.
                    @else
                        Files are written to {{ $providerLabels[$provider] }} and still streamed back through the app, so permissions and download logging are unchanged.
                    @endif
                </div>
            </div>
        </div>

        @php
            // Credentials on file for something that is not the one in use. The
            // single most confusing state this page can be in — "I connected
            // Cloudinary, so why are files still local?" — so it says so.
            $savedButIdle = collect([
                'cloudflare' => $r2Configured,
                'cloudinary' => $cloudinaryConfigured,
            ])->filter()->keys()->reject(fn ($p) => $p === $provider)->values();
        @endphp

        @if($savedButIdle->isNotEmpty())
            <div class="st-note mb-4" style="border-left:3px solid var(--c-yellow)">
                <i class="bi bi-exclamation-triangle me-1" style="color:var(--c-yellow)"></i>
                <strong>{{ $savedButIdle->map(fn ($p) => $providerLabels[$p])->join(' and ') }}
                {{ $savedButIdle->count() === 1 ? 'has' : 'have' }} credentials saved but
                {{ $savedButIdle->count() === 1 ? 'is' : 'are' }} not in use.</strong>
                Uploads still go to {{ $providerLabels[$provider] }}. Saving credentials does not move
                anything — open the provider below and press <em>Activate</em>.
            </div>
        @endif

        {{-- Provider chooser --}}
        <div class="st-tiles">
            @php
                $tiles = [
                    'local' => [
                        'icon' => 'bi-hdd', 'name' => 'Self-hosted',
                        'desc' => "This server's own disk. Always available, no account needed.",
                        'ready' => true,
                    ],
                    'cloudflare' => [
                        'icon' => 'bi-cloud', 'name' => 'Cloudflare R2',
                        'desc' => 'S3-compatible object storage with no egress fees. Handles any file type.',
                        'ready' => $r2Configured,
                    ],
                    'cloudinary' => [
                        'icon' => 'bi-images', 'name' => 'Cloudinary',
                        'desc' => 'Media CDN. Assets are stored raw and delivered from the edge.',
                        'ready' => $cloudinaryConfigured,
                    ],
                ];
            @endphp

            @foreach($tiles as $key => $tile)
                <div class="st-tile {{ $openPanel === $key ? 'selected' : '' }}" data-panel="{{ $key }}">
                    <div class="st-tile-head">
                        <i class="bi {{ $tile['icon'] }} st-tile-icon"></i>
                        <span class="st-tile-name">{{ $tile['name'] }}</span>
                    </div>
                    <div class="st-tile-desc">{{ $tile['desc'] }}</div>
                    <div class="st-tile-state">
                        @if($provider === $key)
                            <span class="spill spill-completed"><i class="bi bi-check-circle me-1"></i>Active</span>
                        @elseif($tile['ready'])
                            <span class="spill spill-pending"><i class="bi bi-plug me-1"></i>Configured</span>
                        @else
                            <span class="spill spill-hold"><i class="bi bi-dash-circle me-1"></i>Not set up</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Self-hosted ─────────────────────────────────────────── --}}
        <div class="st-panel st-config" id="panel-local" style="{{ $openPanel === 'local' ? '' : 'display:none' }}">
            <div class="st-panel-head">
                <h6 class="st-panel-title">Self-hosted storage</h6>
                <span class="st-panel-sub">The default. Nothing to configure.</span>
            </div>
            <div class="st-panel-body">
                <div class="st-note">
                    Files live in <code>storage/app/private</code> on this server and are streamed to users
                    through the application, so every download passes the same permission checks and is
                    recorded the same way. This is the right choice until storage size or bandwidth becomes
                    a problem — moving to a CDN is a switch you can make later without touching existing files.
                </div>
            </div>
            @if($provider !== 'local')
                <div class="st-panel-foot">
                    <form method="POST" action="{{ route('settings.storage.activate') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="provider" value="local">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-hdd me-1"></i>Store new uploads here</button>
                    </form>
                    <span class="st-help mt-0">Files already on a CDN stay there and keep working.</span>
                </div>
            @endif
        </div>

        {{-- ── Cloudflare R2 ───────────────────────────────────────── --}}
        <div class="st-panel st-config" id="panel-cloudflare" style="{{ $openPanel === 'cloudflare' ? '' : 'display:none' }}">
            {{-- The save form wraps only the fields. Activate and Disconnect are
                 their own forms in the footer, so the Save button reaches back
                 into this one by id rather than nesting forms inside each other. --}}
            <form method="POST" action="{{ route('settings.storage.update') }}" id="r2Form">
                @csrf
                <input type="hidden" name="provider" value="cloudflare">

                <div class="st-panel-head">
                    <h6 class="st-panel-title">Cloudflare R2</h6>
                    <span class="st-panel-sub">Create an R2 bucket, then an API token with Object Read &amp; Write on it.</span>
                </div>

                <div class="st-panel-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="st-label" for="r2Account">Account ID</label>
                            <input type="text" id="r2Account" name="account_id" class="form-control font-monospace"
                                   value="{{ old('account_id', $r2['account_id']) }}" placeholder="a1b2c3d4e5f6...">
                            <span class="st-help">R2 → Overview, shown beside the S3 API endpoint.</span>
                            @error('account_id')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="r2Bucket">Bucket name</label>
                            <input type="text" id="r2Bucket" name="bucket" class="form-control"
                                   value="{{ old('bucket', $r2['bucket']) }}" placeholder="dfcp-files">
                            <span class="st-help">The bucket must already exist — this never creates one.</span>
                            @error('bucket')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="r2Key">Access key ID</label>
                            <input type="text" id="r2Key" name="access_key" class="form-control font-monospace"
                                   value="{{ old('access_key', $r2['key']) }}" autocomplete="off">
                            @error('access_key')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="r2Secret">Secret access key</label>
                            <input type="password" id="r2Secret" name="secret" class="form-control font-monospace"
                                   autocomplete="new-password"
                                   placeholder="{{ $hasR2Secret ? '•••••••• saved — leave blank to keep' : 'Shown once when you create the token' }}">
                            <span class="st-help">Stored encrypted and never shown again.</span>
                            @error('secret')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-12">
                            <label class="st-label" for="r2Url">Public delivery URL <span style="color:var(--text3);font-weight:400">— optional</span></label>
                            <input type="text" id="r2Url" name="url" class="form-control"
                                   value="{{ old('url', $r2['url']) }}" placeholder="https://files.example.com">
                            <span class="st-help">
                                A custom domain or r2.dev address, if you have made the bucket public. Leave blank
                                for a private bucket — downloads are streamed through the app either way, so a
                                private bucket is the safer default for client documents.
                            </span>
                            @error('url')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="st-result" id="result-cloudflare"></div>
                </div>
            </form>

            <div class="st-panel-foot">
                <button type="submit" form="r2Form" class="btn btn-sm btn-primary">
                    <i class="bi bi-save me-1"></i>Save credentials
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary st-test" data-provider="cloudflare"
                        {{ $r2Configured ? '' : 'disabled' }}>
                    <i class="bi bi-activity me-1"></i>Test connection
                </button>

                @if($r2Configured && $provider !== 'cloudflare')
                    <form method="POST" action="{{ route('settings.storage.activate') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="provider" value="cloudflare">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-upload me-1"></i>Activate</button>
                    </form>
                @endif
                @if($r2Configured)
                    <form method="POST" action="{{ route('settings.storage.disconnect') }}" class="m-0 ms-auto"
                          onsubmit="return confirm('Remove these credentials? Files already stored on R2 stay there but this app will no longer be able to read them.')">
                        @csrf
                        <input type="hidden" name="provider" value="cloudflare">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Disconnect</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- ── Cloudinary ──────────────────────────────────────────── --}}
        <div class="st-panel st-config" id="panel-cloudinary" style="{{ $openPanel === 'cloudinary' ? '' : 'display:none' }}">
            <form method="POST" action="{{ route('settings.storage.update') }}" id="cloudinaryForm">
                @csrf
                <input type="hidden" name="provider" value="cloudinary">

                <div class="st-panel-head">
                    <h6 class="st-panel-title">Cloudinary</h6>
                    <span class="st-panel-sub">Credentials are on the Cloudinary dashboard, under Account Details.</span>
                </div>

                <div class="st-panel-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="st-label" for="cdyCloud">Cloud name</label>
                            <input type="text" id="cdyCloud" name="cloud_name" class="form-control"
                                   value="{{ old('cloud_name', $cloudinary['cloud_name']) }}" placeholder="my-company">
                            @error('cloud_name')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="cdyFolder">Folder <span style="color:var(--text3);font-weight:400">— optional</span></label>
                            <input type="text" id="cdyFolder" name="folder" class="form-control"
                                   value="{{ old('folder', $cloudinary['folder']) }}" placeholder="dfcp">
                            <span class="st-help">Keeps this app's files apart if the cloud is shared.</span>
                            @error('folder')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="cdyKey">API key</label>
                            <input type="text" id="cdyKey" name="api_key" class="form-control font-monospace"
                                   value="{{ old('api_key', $cloudinary['api_key']) }}" autocomplete="off">
                            @error('api_key')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="st-label" for="cdySecret">API secret</label>
                            <input type="password" id="cdySecret" name="api_secret" class="form-control font-monospace"
                                   autocomplete="new-password"
                                   placeholder="{{ $hasCloudinarySecret ? '•••••••• saved — leave blank to keep' : 'From Account Details' }}">
                            <span class="st-help">Stored encrypted and never shown again.</span>
                            @error('api_secret')<span class="st-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-12">
                            <div class="st-note">
                                <strong>Worth knowing before you switch.</strong> Cloudinary delivers every asset from
                                its public CDN, so a file is readable by anyone holding its exact URL. Stored names are
                                random UUIDs, which makes those URLs impractical to guess, and the app still streams
                                downloads through its own permission checks — but for strictly confidential documents a
                                private Cloudflare R2 bucket is the stronger choice.
                            </div>
                        </div>
                    </div>

                    <div class="st-result" id="result-cloudinary"></div>
                </div>
            </form>

            <div class="st-panel-foot">
                <button type="submit" form="cloudinaryForm" class="btn btn-sm btn-primary">
                    <i class="bi bi-save me-1"></i>Save credentials
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary st-test" data-provider="cloudinary"
                        {{ $cloudinaryConfigured ? '' : 'disabled' }}>
                    <i class="bi bi-activity me-1"></i>Test connection
                </button>

                @if($cloudinaryConfigured && $provider !== 'cloudinary')
                    <form method="POST" action="{{ route('settings.storage.activate') }}" class="m-0">
                        @csrf
                        <input type="hidden" name="provider" value="cloudinary">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-upload me-1"></i>Activate</button>
                    </form>
                @endif
                @if($cloudinaryConfigured)
                    <form method="POST" action="{{ route('settings.storage.disconnect') }}" class="m-0 ms-auto"
                          onsubmit="return confirm('Remove these credentials? Files already stored on Cloudinary stay there but this app will no longer be able to read them.')">
                        @csrf
                        <input type="hidden" name="provider" value="cloudinary">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Disconnect</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Where the files that already exist are living --}}
        <div class="st-panel mt-4">
            <div class="st-panel-head">
                <h6 class="st-panel-title">Files on record</h6>
                <span class="st-panel-sub">Every stored file remembers its own location, so changing provider never strands what came before.</span>
            </div>
            <div class="st-panel-body">
                @if(empty($usage))
                    <div style="font-size:var(--fs-xs);color:var(--text3)">No files stored yet.</div>
                @else
                    <div class="st-usage">
                        @foreach($usage as $disk => $count)
                            <span class="st-usage-item">
                                <i class="bi {{ $disk === 'local' ? 'bi-hdd' : 'bi-cloud' }}"></i>
                                {{ $providerLabels[$disk] ?? $disk }}
                                <span class="st-usage-count">{{ number_format($count) }}</span>
                                {{ Str::plural('file', $count) }}
                            </span>
                        @endforeach
                    </div>
                @endif
                <span class="st-help">
                    The File Manager drive is not listed here: it is a browsable folder tree with no per-file
                    record, so it stays on this server regardless of the provider chosen above.
                </span>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(function () {
        // Tiles are a view switch, not the setting itself — activating is an
        // explicit, confirmed action inside the panel.
        $('.st-tile').on('click', function () {
            var target = $(this).data('panel');
            $('.st-tile').removeClass('selected');
            $(this).addClass('selected');
            $('.st-config').hide();
            $('#panel-' + target).show();
        });

        $('.st-test').on('click', function () {
            var provider = $(this).data('provider');
            var $btn = $(this);
            var $out = $('#result-' + provider);
            var original = $btn.html();

            $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Testing…');
            $out.hide().empty();

            $.post('{{ route('settings.storage.test') }}', { provider: provider })
                .done(function (r) {
                    var html = '';
                    (r.steps || []).forEach(function (step) {
                        html += '<div class="st-step ' + (step.ok ? 'ok' : 'bad') + '">'
                             +  '<i class="bi ' + (step.ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + '"></i>'
                             +  $('<span>').text(step.label).html()
                             +  '</div>';
                    });
                    html += '<div class="st-result-msg" style="color:' + (r.ok ? 'var(--c-green)' : 'var(--c-red)') + '">'
                         +  $('<span>').text(r.message).html() + '</div>';
                    $out.html(html).show();
                })
                .fail(function () {
                    $out.html('<div class="st-result-msg" style="color:var(--c-red)">The test could not be run. Check the application log.</div>').show();
                })
                .always(function () {
                    $btn.prop('disabled', false).html(original);
                });
        });
    });
</script>
@endpush
