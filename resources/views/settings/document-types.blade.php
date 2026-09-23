@extends('layouts.app')
@section('title', 'Document Types')

@push('styles')
<style>
    .dt-table { font-size: var(--fs-sm); }
    .dt-table th { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 700; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap; }
    .dt-table td { border-color: var(--border); color: var(--text2); vertical-align: middle; }
    .dt-name { font-weight: 600; color: var(--text); }
    .dt-desc { font-size: var(--fs-2xs); color: var(--text3); }
    .dt-row-off td { opacity: .55; }

    .dt-icon {
        width: 32px; height: 32px; border-radius: var(--radius-sm); flex-shrink: 0;
        display: inline-flex; align-items: center; justify-content: center;
        background: rgba(var(--primary-rgb), .1); color: var(--primary);
    }
    .dt-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .dt-btn {
        background: var(--surface2); border: 1px solid var(--border); color: var(--text2);
        padding: 2px 8px; border-radius: var(--radius-sm); font-size: .8rem;
    }
    .dt-btn:hover { color: var(--text); border-color: var(--text3); }
    .dt-btn-danger { background: var(--c-red-bg); border-color: var(--c-red-bg); color: var(--c-red); }
    .dt-tag { font-size: var(--fs-2xs); padding: 1px 7px; border-radius: 20px; border: 1px solid var(--border); background: var(--surface2); color: var(--text3); }

    .dt-picker { display: flex; flex-wrap: wrap; gap: 6px; }
    .dt-pick {
        width: 34px; height: 34px; border-radius: var(--radius-sm);
        border: 1px solid var(--border); background: var(--surface);
        color: var(--text2); display: inline-flex; align-items: center; justify-content: center;
    }
    .dt-pick:hover { border-color: var(--primary); color: var(--primary); }
    .dt-pick.active { border-color: var(--primary); color: var(--primary); background: rgba(var(--primary-rgb), .12); }
    .form-switch .form-check-input { cursor: pointer; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-folder2-open me-2"></i>Document Types</h4>
        <small style="color:var(--text3)">What a client's documents are filed under.</small>
    </div>
    <button class="btn btn-sm btn-primary" id="dtAdd"><i class="bi bi-plus-lg me-1"></i>Add Type</button>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'document-types'])

<div>
    <div class="dt-note mb-3">
        <i class="bi bi-info-circle me-1"></i>
        These are the choices in <strong>Client → Documents → Upload</strong>. Mark a type
        <strong>client can upload</strong> and it also appears in the client's own portal. A type with
        documents filed under it can't be deleted — switch it off and it disappears from both upload
        forms while old documents keep their label.
    </div>

    <div class="card section-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table dt-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Type</th>
                            <th class="text-end">Documents</th>
                            <th class="text-center">Required</th>
                            <th class="text-center">Client can upload</th>
                            <th class="text-center">Order</th>
                            <th class="text-center">Active</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($types as $t)
                        <tr class="{{ $t->is_active ? '' : 'dt-row-off' }}" data-id="{{ $t->id }}">
                            <td class="ps-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="dt-icon"><i class="bi {{ $t->icon ?: 'bi-file-earmark' }}"></i></span>
                                    <div style="min-width:0">
                                        <div class="dt-name">{{ $t->name }}</div>
                                        @if($t->description)<div class="dt-desc">{{ $t->description }}</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="text-end">{{ $t->documents_count }}</td>
                            <td class="text-center">
                                @if($t->is_required)<span class="dt-tag" style="color:var(--c-yellow);border-color:var(--c-yellow)">Required</span>@else<span style="color:var(--text3)">—</span>@endif
                            </td>
                            <td class="text-center">
                                @if($t->is_client_submittable)<i class="bi bi-check-lg" style="color:var(--c-green)"></i>@else<span style="color:var(--text3)">—</span>@endif
                            </td>
                            <td class="text-center">{{ $t->sort_order }}</td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input dt-toggle" type="checkbox" data-id="{{ $t->id }}" @checked($t->is_active) aria-label="Active">
                                </div>
                            </td>
                            <td class="text-end pe-3" style="white-space:nowrap">
                                <button class="dt-btn dt-edit" title="Edit"
                                        data-id="{{ $t->id }}" data-name="{{ $t->name }}"
                                        data-description="{{ $t->description }}" data-icon="{{ $t->icon }}"
                                        data-required="{{ (int) $t->is_required }}"
                                        data-submittable="{{ (int) $t->is_client_submittable }}"
                                        data-sort="{{ $t->sort_order }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if(!$t->documents_count)
                                    <button class="dt-btn dt-btn-danger dt-delete" title="Delete" data-id="{{ $t->id }}" data-name="{{ $t->name }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center py-4" style="color:var(--text3)">No document types yet — add the first one.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="dtModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold" id="dtModalTitle">Add Document Type</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dtId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small" for="dtName">Name <span style="color:var(--c-red)">*</span></label>
                    <input type="text" id="dtName" class="form-control" maxlength="100" placeholder="e.g. Bank Statement">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small" for="dtDescription">Description</label>
                    <input type="text" id="dtDescription" class="form-control" maxlength="255" placeholder="Optional — what belongs here">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Icon</label>
                    <div class="dt-picker" id="dtIcons">
                        @foreach($icons as $icon)
                            <button type="button" class="dt-pick" data-icon="{{ $icon }}" title="{{ $icon }}"><i class="bi {{ $icon }}"></i></button>
                        @endforeach
                    </div>
                    <input type="hidden" id="dtIcon" value="bi-file-earmark">
                </div>
                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="dtRequired">
                        <label class="form-check-label small" for="dtRequired">Required document</label>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="dtSubmittable">
                        <label class="form-check-label small" for="dtSubmittable">Client can upload it</label>
                    </div>
                </div>
                <div>
                    <label class="form-label fw-semibold small" for="dtSort">Display order</label>
                    <input type="number" id="dtSort" class="form-control" min="0" placeholder="Lower shows first">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="dtSave" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const modal = new bootstrap.Modal('#dtModal');
    const base  = '{{ url('settings/document-types') }}';
    const fail  = x => Swal.fire('Could not save', x.responseJSON?.message
        || Object.values(x.responseJSON?.errors || {})[0]?.[0]
        || 'Something went wrong.', 'error');

    function pickIcon(icon) {
        $('#dtIcon').val(icon || 'bi-file-earmark');
        $('#dtIcons .dt-pick').removeClass('active').filter('[data-icon="' + $('#dtIcon').val() + '"]').addClass('active');
    }

    $('#dtIcons').on('click', '.dt-pick', function () { pickIcon($(this).data('icon')); });

    $('#dtAdd').on('click', function () {
        $('#dtModalTitle').text('Add Document Type');
        $('#dtId,#dtName,#dtDescription,#dtSort').val('');
        $('#dtRequired,#dtSubmittable').prop('checked', false);
        pickIcon('bi-file-earmark');
        modal.show();
    });

    $(document).on('click', '.dt-edit', function () {
        const b = $(this);
        $('#dtModalTitle').text('Edit Document Type');
        $('#dtId').val(b.data('id'));
        $('#dtName').val(b.data('name'));
        $('#dtDescription').val(b.data('description') || '');
        $('#dtSort').val(b.data('sort'));
        $('#dtRequired').prop('checked', String(b.data('required')) === '1');
        $('#dtSubmittable').prop('checked', String(b.data('submittable')) === '1');
        pickIcon(b.data('icon'));
        modal.show();
    });

    $('#dtSave').on('click', function () {
        const id = $('#dtId').val();
        const name = $('#dtName').val().trim();

        if (!name) { Swal.fire('Name it', 'A document type needs a name.', 'info'); return; }

        const payload = {
            name: name,
            description: $('#dtDescription').val().trim(),
            icon: $('#dtIcon').val(),
            is_required: $('#dtRequired').is(':checked') ? 1 : 0,
            is_client_submittable: $('#dtSubmittable').is(':checked') ? 1 : 0,
        };
        if ($('#dtSort').val() !== '') payload.sort_order = $('#dtSort').val();

        const $btn = $(this).prop('disabled', true);
        $.ajax({ url: id ? base + '/' + id : base, type: id ? 'PUT' : 'POST', data: payload })
            .done(() => location.reload())
            .fail(fail)
            .always(() => $btn.prop('disabled', false));
    });

    $(document).on('change', '.dt-toggle', function () {
        const box = $(this);
        $.ajax({ url: base + '/' + box.data('id'), type: 'PUT', data: { is_active: box.is(':checked') ? 1 : 0 } })
            .done(() => box.closest('tr').toggleClass('dt-row-off', !box.is(':checked')))
            .fail(x => { box.prop('checked', !box.is(':checked')); fail(x); });
    });

    $(document).on('click', '.dt-delete', function () {
        const b = $(this);
        Swal.fire({
            title: 'Delete "' + b.data('name') + '"?',
            text: 'Nothing is filed under it, so it can go for good.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545',
        }).then(r => {
            if (r.isConfirmed) $.ajax({ url: base + '/' + b.data('id'), type: 'DELETE' }).done(() => location.reload()).fail(fail);
        });
    });
})();
</script>
@endpush
