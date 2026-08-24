@extends('layouts.app')
@section('title', 'Chat Settings')

@push('styles')
<style>
    .ch-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .ch-label { font-size: var(--fs-sm); font-weight: 600; color: var(--text2); margin-bottom: 4px; display: block; }
    .ch-help { font-size: var(--fs-2xs); color: var(--text3); margin-top: 4px; display: block; line-height: 1.5; }

    .ch-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: var(--space-3); }
    .ch-stat {
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .ch-stat-label { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); }
    .ch-stat-value { font-size: 1.15rem; font-weight: 700; color: var(--text); line-height: 1.2; margin-top: 2px; }

    .ch-state { display: flex; align-items: center; gap: var(--space-3); }
    .ch-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
    .ch-dot.on { background: var(--c-yellow); }
    .ch-dot.off { background: var(--text3); }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-chat-dots me-2"></i>Chat</h4>
        <small style="color:var(--text3)">How long files sent in the internal chat are kept.</small>
    </div>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'chat'])

<div>
    @if(session('success'))
        <div class="ch-note mb-4" style="border-left:3px solid var(--c-green)">
            <i class="bi bi-check-circle me-1" style="color:var(--c-green)"></i>{{ session('success') }}
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- What is currently being held --}}
            <div class="card section-card mb-4">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Files on record</h6>
                    <small style="color:var(--text3)">Every attachment sent in the internal chat is tracked here.</small>
                </div>
                <div class="card-body">
                    <div class="ch-stats">
                        <div class="ch-stat">
                            <div class="ch-stat-label">Stored</div>
                            <div class="ch-stat-value">{{ number_format($inventory['files']) }}</div>
                        </div>
                        <div class="ch-stat">
                            <div class="ch-stat-label">Space used</div>
                            <div class="ch-stat-value">{{ \App\Services\Chat\ChatAttachmentPruner::formatBytes($inventory['bytes']) }}</div>
                        </div>
                        <div class="ch-stat">
                            <div class="ch-stat-label">Already removed</div>
                            <div class="ch-stat-value">{{ number_format($inventory['purged']) }}</div>
                        </div>
                    </div>

                    @if($inventory['oldest'])
                        <span class="ch-help">
                            Oldest file still held was sent
                            {{ \Illuminate\Support\Carbon::parse($inventory['oldest'])->diffForHumans() }}.
                        </span>
                    @endif
                </div>
            </div>

            {{-- The policy --}}
            <form method="POST" action="{{ route('settings.chat.update') }}">
                @csrf

                <div class="card section-card mb-4">
                    <div class="card-header py-3">
                        <h6 class="fw-bold mb-0">Retention</h6>
                        <small style="color:var(--text3)">Off by default — nothing is deleted until you turn this on.</small>
                    </div>
                    <div class="card-body">
                        <div class="ch-state mb-3">
                            <span class="ch-dot {{ $enabled ? 'on' : 'off' }}"></span>
                            <div style="font-size:.84rem;color:var(--text2)">{{ $summary }}</div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="chRetain" name="retention_enabled" value="1"
                                   {{ old('retention_enabled', $enabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="chRetain" style="font-size:.86rem">
                                Delete chat attachments automatically
                            </label>
                        </div>

                        <div id="chDaysWrap" class="{{ $enabled ? '' : 'd-none' }}">
                            <label class="ch-label" for="chDays">Delete after</label>
                            <div class="d-flex align-items-center gap-2" style="max-width:260px">
                                <input type="number" id="chDays" name="retention_days" class="form-control"
                                       value="{{ old('retention_days', $days) }}"
                                       min="{{ $minDays }}" max="{{ $maxDays }}">
                                <span style="font-size:.84rem;color:var(--text3)">days</span>
                            </div>
                            <span class="ch-help">
                                Counted from when the message was sent. The message and the file's name
                                are kept — only the file itself is removed, and the thread shows that it
                                expired rather than leaving a gap.
                            </span>
                            @error('retention_days')<span class="ch-help" style="color:var(--c-red)">{{ $message }}</span>@enderror
                        </div>

                        <div class="ch-note mt-3" style="border-left:3px solid var(--c-yellow)">
                            <i class="bi bi-exclamation-triangle me-1" style="color:var(--c-yellow)"></i>
                            <strong>Deleted files cannot be recovered.</strong> This runs nightly and removes
                            attachments from wherever they are stored, including a connected CDN. Voice
                            messages and images count as attachments.
                        </div>
                    </div>
                    <div class="card-body pt-0 d-flex gap-2 flex-wrap align-items-center">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="chPreview"
                                {{ $enabled ? '' : 'disabled' }}>
                            <i class="bi bi-search me-1"></i>What would be deleted?
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="chRun"
                                {{ $enabled ? '' : 'disabled' }}>
                            <i class="bi bi-trash me-1"></i>Run now
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card section-card" style="position:sticky;top:72px">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">How it works</h6>
                </div>
                <div class="card-body">
                    <ul style="font-size:.78rem;color:var(--text2);padding-left:1.1rem;line-height:1.75">
                        <li>Runs automatically each night at 03:30.</li>
                        <li>Only the <strong>file</strong> is deleted. The message, who sent it and when,
                            and the file's name all stay.</li>
                        <li>A pruned attachment shows in the thread as
                            <em>“report.pdf — no longer available”</em>.</li>
                        <li>Files are removed from the disk they were written to, including a CDN.</li>
                        <li>Chat monitors see the same note — nothing is hidden from the audit trail.</li>
                        <li>Every run is recorded in the activity log.</li>
                    </ul>

                    <div class="ch-note">
                        This applies to the <strong>internal chat only</strong>. Client documents, portal
                        uploads and WhatsApp media are not affected.
                    </div>

                    <div class="ch-note mt-3">
                        Needs the scheduler running:
                        <div style="font-family:ui-monospace,monospace;font-size:.7rem;margin-top:.4rem">
                            php artisan schedule:work
                        </div>
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
    $('#chRetain').on('change', function () {
        $('#chDaysWrap').toggleClass('d-none', !this.checked);
        // The action buttons act on the *saved* policy, so they stay disabled
        // until the change has actually been saved.
        $('#chPreview, #chRun').prop('disabled', true);
    });

    $('#chPreview').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post('{{ route('settings.chat.preview') }}')
            .done(function (r) {
                if (!r.success) { Swal.fire('Nothing to do', r.message, 'info'); return; }

                Swal.fire({
                    icon: r.count ? 'info' : 'success',
                    title: r.count ? r.count + ' file(s) would be deleted' : 'Nothing to delete',
                    html: r.count
                        ? 'Everything sent before <strong>' + $('<span>').text(r.cutoff).html() + '</strong>'
                          + ', freeing about <strong>' + $('<span>').text(r.size).html() + '</strong>.'
                        : 'No attachment is older than the retention period yet.',
                });
            })
            .fail(function () { Swal.fire('Error', 'Could not work that out.', 'error'); })
            .always(function () { $btn.prop('disabled', false); });
    });

    $('#chRun').on('click', function () {
        Swal.fire({
            title: 'Delete them now?',
            text: 'Attachments past the retention period will be permanently removed. This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete them',
            confirmButtonColor: '#dc3545',
        }).then(function (res) {
            if (!res.isConfirmed) return;

            $.post('{{ route('settings.chat.run') }}')
                .done(function (r) {
                    Swal.fire('Done', r.message, 'success').then(() => window.location.reload());
                })
                .fail(function (x) {
                    Swal.fire('Error', x.responseJSON?.message || 'The clean-up could not be run.', 'error');
                });
        });
    });
</script>
@endpush
