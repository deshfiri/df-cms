@extends('layouts.app')
@section('title', 'Payment Categories')

@push('styles')
<style>
    .pc-table { font-size: var(--fs-sm); }
    .pc-table th { font-size: var(--fs-2xs); text-transform: uppercase; letter-spacing: .05em; color: var(--text3); font-weight: 700; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap; }
    .pc-table td { border-color: var(--border); color: var(--text2); vertical-align: middle; }
    .pc-name { font-weight: 600; color: var(--text); }
    .pc-desc { font-size: var(--fs-2xs); color: var(--text3); }
    .pc-money { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pc-row-off td { opacity: .55; }
    .pc-note {
        font-size: var(--fs-xs); color: var(--text2); line-height: 1.6;
        background: var(--surface2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: var(--space-3) var(--space-4);
    }
    .pc-btn {
        background: var(--surface2); border: 1px solid var(--border); color: var(--text2);
        padding: 2px 8px; border-radius: var(--radius-sm); font-size: .8rem;
    }
    .pc-btn:hover { color: var(--text); border-color: var(--text3); }
    .pc-btn-danger { background: var(--c-red-bg); border-color: var(--c-red-bg); color: var(--c-red); }
    .form-switch .form-check-input { cursor: pointer; }
</style>
@endpush

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-wallet2 me-2"></i>Payment Categories</h4>
        <small style="color:var(--text3)">What clients are billed for. Each charge and payment is filed under one.</small>
    </div>
    <button class="btn btn-sm btn-primary" id="pcAdd"><i class="bi bi-plus-lg me-1"></i>Add Category</button>
</div>

<div class="set-layout">
@include('settings.partials.nav', ['active' => 'payment-categories'])

<div>
    <div class="pc-note mb-3">
        <i class="bi bi-info-circle me-1"></i>
        A client billed <strong>৳20,000 for Social Media Ads</strong> gets a charge under that category; every payment
        against it — ৳10,000 now, the rest later — reduces its balance. Categories that have been used can't be deleted,
        only deactivated, so old records keep their label.
    </div>

    <div class="card section-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table pc-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Category</th>
                            <th class="text-end">Charges</th>
                            <th class="text-end">Billed</th>
                            <th class="text-end">Received</th>
                            <th class="text-center">Active</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($categories as $c)
                        <tr class="{{ $c->is_active ? '' : 'pc-row-off' }}">
                            <td class="ps-3">
                                <div class="pc-name">{{ $c->name }}</div>
                                @if($c->description)<div class="pc-desc">{{ $c->description }}</div>@endif
                            </td>
                            <td class="text-end">{{ $c->invoices_count }}</td>
                            <td class="text-end pc-money">৳{{ number_format((float) ($billed[$c->id] ?? 0), 0) }}</td>
                            <td class="text-end pc-money" style="color:var(--c-green)">৳{{ number_format((float) ($received[$c->id] ?? 0), 0) }}</td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input class="form-check-input pc-toggle" type="checkbox" data-id="{{ $c->id }}" @checked($c->is_active) aria-label="Active">
                                </div>
                            </td>
                            <td class="text-end pe-3" style="white-space:nowrap">
                                <button class="pc-btn pc-edit" title="Edit"
                                        data-id="{{ $c->id }}" data-name="{{ $c->name }}"
                                        data-description="{{ $c->description }}" data-sort="{{ $c->sort_order }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if(!$c->invoices_count && !$c->payments_count)
                                    <button class="pc-btn pc-btn-danger pc-delete" title="Delete" data-id="{{ $c->id }}" data-name="{{ $c->name }}">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">No categories yet — add the first one.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="pcModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold" id="pcModalTitle">Add Category</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pcId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Name <span style="color:var(--c-red)">*</span></label>
                    <input type="text" id="pcName" class="form-control" maxlength="100" placeholder="e.g. Social Media Ads">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Description</label>
                    <input type="text" id="pcDescription" class="form-control" maxlength="255" placeholder="Optional — what belongs here">
                </div>
                <div>
                    <label class="form-label fw-semibold small">Display order</label>
                    <input type="number" id="pcSort" class="form-control" min="0" placeholder="Lower shows first">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="pcSave" class="btn btn-sm btn-primary"><i class="bi bi-check me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const modal = new bootstrap.Modal('#pcModal');
    const base  = '{{ url('settings/payment-categories') }}';
    const fail  = x => Swal.fire('Error', x.responseJSON?.message || 'Something went wrong.', 'error');

    $('#pcAdd').on('click', function () {
        $('#pcModalTitle').text('Add Category');
        $('#pcId,#pcName,#pcDescription,#pcSort').val('');
        modal.show();
    });

    $(document).on('click', '.pc-edit', function () {
        const b = $(this);
        $('#pcModalTitle').text('Edit Category');
        $('#pcId').val(b.data('id'));
        $('#pcName').val(b.data('name'));
        $('#pcDescription').val(b.data('description') || '');
        $('#pcSort').val(b.data('sort'));
        modal.show();
    });

    $('#pcSave').on('click', function () {
        const id = $('#pcId').val();
        const payload = { name: $('#pcName').val().trim(), description: $('#pcDescription').val().trim() };
        if ($('#pcSort').val() !== '') payload.sort_order = $('#pcSort').val();

        $.ajax({ url: id ? base + '/' + id : base, type: id ? 'PUT' : 'POST', data: payload })
            .done(() => location.reload())
            .fail(fail);
    });

    $(document).on('change', '.pc-toggle', function () {
        const box = $(this);
        $.ajax({ url: base + '/' + box.data('id'), type: 'PUT', data: { is_active: box.is(':checked') ? 1 : 0 } })
            .done(() => box.closest('tr').toggleClass('pc-row-off', !box.is(':checked')))
            .fail(x => { box.prop('checked', !box.is(':checked')); fail(x); });
    });

    $(document).on('click', '.pc-delete', function () {
        const b = $(this);
        Swal.fire({ title: 'Delete "' + b.data('name') + '"?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) $.ajax({ url: base + '/' + b.data('id'), type: 'DELETE' }).done(() => location.reload()).fail(fail);
            });
    });
})();
</script>
@endpush
