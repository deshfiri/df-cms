@extends('layouts.app')
@section('title', 'Categories')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-tags me-2"></i>Categories</h4>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCatModal">
        <i class="bi bi-plus-lg me-1"></i>Add Category
    </button>
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <table id="catTable" class="table table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr><th class="ps-3">Name</th><th>Clients</th><th>Status</th><th class="pe-3">Actions</th></tr>
            </thead>
        </table>
    </div>
</div>

{{-- Add Modal --}}
<div class="modal fade" id="addCatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Add Category</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <label class="form-label fw-semibold small">Name</label>
                <input type="text" id="catName" class="form-control" placeholder="Category name">
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveCat" class="btn btn-sm btn-primary">Add</button>
            </div>
        </div>
    </div>
</div>

{{-- Edit Modal --}}
<div class="modal fade" id="editCatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Edit Category</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="editCatId">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Name</label>
                    <input type="text" id="editCatName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Status</label>
                    <select id="editCatStatus" class="form-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="updateCat" class="btn btn-sm btn-warning">Update</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const table = $('#catTable').DataTable({
    serverSide: true, processing: true,
    ajax: '{{ route("categories.index") }}',
    columns: [
        { data: 'name', className: 'ps-3' },
        { data: 'clients_count' },
        { data: 'status_badge' },
        { data: 'actions', orderable: false, className: 'pe-3' }
    ],
    order: [[0, 'asc']], pageLength: 25,
});

$('#saveCat').on('click', function () {
    $.post('{{ route("categories.store") }}', { name: $('#catName').val() })
     .done(function () { bootstrap.Modal.getInstance('#addCatModal').hide(); $('#catName').val(''); table.ajax.reload(); })
     .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Failed', 'error'); });
});

$(document).on('click', '.btn-edit', function () {
    $('#editCatId').val($(this).data('id'));
    $('#editCatName').val($(this).data('name'));
    $('#editCatStatus').val($(this).data('status'));
    new bootstrap.Modal('#editCatModal').show();
});

$('#updateCat').on('click', function () {
    const id = $('#editCatId').val();
    $.ajax({ url: '/categories/' + id, type: 'PUT', data: { name: $('#editCatName').val(), status: $('#editCatStatus').val() } })
     .done(function () { bootstrap.Modal.getInstance('#editCatModal').hide(); table.ajax.reload(); });
});

$(document).on('click', '.btn-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete category?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
    .then(r => {
        if (r.isConfirmed) $.ajax({ url: '/categories/' + id, type: 'DELETE' })
            .done(() => table.ajax.reload())
            .fail(r2 => Swal.fire('Error', r2.responseJSON?.message || 'Failed', 'error'));
    });
});
</script>
@endpush
