@extends('layouts.app')
@section('title', 'Forbidden Words')

@push('styles')
<style>
    .fw-table { font-size: var(--fs-sm); }
    .fw-table th { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 700; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap; }
    .fw-table td { border-color: var(--border); color: var(--text2); vertical-align: middle; }
    .fw-word { font-weight: 600; color: var(--text); font-family: monospace; }
    .fw-row-off td { opacity: .55; }
    .fw-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .fw-btn {
        background: var(--surface2); border: 1px solid var(--border); color: var(--text2);
        padding: 2px 8px; border-radius: var(--radius-sm); font-size: .8rem;
    }
    .fw-btn:hover { color: var(--text); border-color: var(--text3); }
    .fw-btn-danger { background: var(--c-red-bg); border-color: var(--c-red-bg); color: var(--c-red); }
    .form-switch .form-check-input { cursor: pointer; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-shield-exclamation me-2"></i>Forbidden Words</h4>
        <small style="color:var(--text3)">Words and phrases warned on in the internal chat.</small>
    </div>
    <button class="btn btn-sm btn-primary" id="fwAdd"><i class="bi bi-plus-lg me-1"></i>Add Word</button>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'forbidden-words'])

<div>
    <div class="fw-note mb-3">
        <i class="bi bi-info-circle me-1"></i>
        Matching is whole-word and case-insensitive, so banning "ass" won't flag "class" or "assignment". A message
        that uses one of these is still sent as normal — the sender gets a warning, and you get a summary here.
    </div>

    <div class="card section-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table fw-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Word / phrase</th>
                            <th>Added by</th>
                            <th class="text-center">Active</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($words as $w)
                        <tr class="{{ $w->is_active ? '' : 'fw-row-off' }}" data-id="{{ $w->id }}">
                            <td class="ps-3"><span class="fw-word">{{ $w->word }}</span></td>
                            <td>{{ $w->creator->name ?? '—' }}</td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input fw-toggle" type="checkbox" data-id="{{ $w->id }}" @checked($w->is_active) aria-label="Active">
                                </div>
                            </td>
                            <td class="text-end pe-3" style="white-space:nowrap">
                                <button class="fw-btn fw-btn-danger fw-delete" title="Delete" data-id="{{ $w->id }}" data-word="{{ $w->word }}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text3)">No forbidden words yet — add the first one.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="fwModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">Add Forbidden Word</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold small" for="fwWord">Word or phrase <span style="color:var(--c-red)">*</span></label>
                <input type="text" id="fwWord" class="form-control" maxlength="150" placeholder="e.g. some-word">
                <div class="form-text">Not case-sensitive, and matched as a whole word — no need for wildcards.</div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="fwSave" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const modal = new bootstrap.Modal('#fwModal');
    const base  = '{{ url('settings/forbidden-words') }}';
    const fail  = x => Swal.fire('Could not save', x.responseJSON?.message
        || Object.values(x.responseJSON?.errors || {})[0]?.[0]
        || 'Something went wrong.', 'error');

    $('#fwAdd').on('click', function () {
        $('#fwWord').val('');
        modal.show();
    });

    $('#fwSave').on('click', function () {
        const word = $('#fwWord').val().trim();
        if (!word) { Swal.fire('Add a word', 'Type the word or phrase to block first.', 'info'); return; }

        const $btn = $(this).prop('disabled', true);
        $.ajax({ url: base, type: 'POST', data: { word: word } })
            .done(() => location.reload())
            .fail(fail)
            .always(() => $btn.prop('disabled', false));
    });

    $(document).on('change', '.fw-toggle', function () {
        const box = $(this);
        $.ajax({ url: base + '/' + box.data('id'), type: 'PUT', data: { is_active: box.is(':checked') ? 1 : 0 } })
            .done(() => box.closest('tr').toggleClass('fw-row-off', !box.is(':checked')))
            .fail(x => { box.prop('checked', !box.is(':checked')); fail(x); });
    });

    $(document).on('click', '.fw-delete', function () {
        const b = $(this);
        Swal.fire({
            title: 'Delete "' + b.data('word') + '"?',
            text: 'The chat will stop warning on it.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545',
        }).then(r => {
            if (r.isConfirmed) $.ajax({ url: base + '/' + b.data('id'), type: 'DELETE' }).done(() => location.reload()).fail(fail);
        });
    });
})();
</script>
@endpush
