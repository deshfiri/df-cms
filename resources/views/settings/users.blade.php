@extends('layouts.app')
@section('title', 'Users')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-person-gear me-2"></i>System Users</h4>
    @can('manage users')
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-plus-lg me-1"></i>Add User
    </button>
    @endcan
</div>

<div class="card section-card">
    <div class="card-body p-0">
        <table id="userTable" class="table table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr><th class="ps-3">Name</th><th>Email</th><th>Role</th><th>Status</th><th class="pe-3">Actions</th></tr>
            </thead>
        </table>
    </div>
</div>

{{-- Add Modal --}}
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Add User</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Name</label>
                        <input type="text" id="uName" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Email</label>
                        <input type="email" id="uEmail" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Password</label>
                        <input type="password" id="uPass" class="form-control" placeholder="Min 8 chars">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Roles</label>
                        <select id="uRole" class="form-select select2" multiple>
                            @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="saveUser" class="btn btn-sm btn-primary">Create</button>
            </div>
        </div>
    </div>
</div>

{{-- Edit Modal --}}
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3"><h6 class="modal-title fw-bold">Edit User</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" id="editUserId">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Name</label>
                        <input type="text" id="euName" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Email</label>
                        <input type="email" id="euEmail" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">New Password</label>
                        <input type="password" id="euPass" class="form-control" placeholder="Leave blank to keep">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Roles</label>
                        <select id="euRole" class="form-select select2" multiple>
                            @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Status</label>
                        <select id="euStatus" class="form-select">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="updateUser" class="btn btn-sm btn-warning">Update</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const table = $('#userTable').DataTable({
    serverSide: true, processing: true,
    ajax: '{{ route("users.index") }}',
    columns: [
        { data: 'name', className: 'ps-3' },
        { data: 'email' },
        { data: 'roles_badges' },
        { data: 'status_badge' },
        { data: 'actions', orderable: false, className: 'pe-3' }
    ],
    order: [[0, 'asc']], pageLength: 25,
});

$('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

$('#saveUser').on('click', function () {
    $.post('{{ route("users.store") }}', { name: $('#uName').val(), email: $('#uEmail').val(), password: $('#uPass').val(), roles: $('#uRole').val() || [] })
     .done(function () {
        bootstrap.Modal.getInstance('#addUserModal').hide();
        $('#uName,#uEmail,#uPass').val('');
        $('#uRole').val(null).trigger('change');
        table.ajax.reload();
     })
     .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Failed', 'error'); });
});

$(document).on('click', '.btn-edit', function () {
    const id = $(this).data('id');
    const roles = ($(this).data('roles') || '').toString().split(',').filter(Boolean);

    $('#editUserId').val(id);
    $('#euName').val($(this).data('name'));
    $('#euEmail').val($(this).data('email'));
    $('#euPass').val('');
    $('#euStatus').val($(this).data('active') == 1 ? '1' : '0');
    $('#euRole').val(roles).trigger('change');

    new bootstrap.Modal('#editUserModal').show();
});

$('#updateUser').on('click', function () {
    const id = $('#editUserId').val();
    $.ajax({ url: '/users/' + id, type: 'PUT', data: { name: $('#euName').val(), email: $('#euEmail').val(), password: $('#euPass').val(), roles: $('#euRole').val() || [], is_active: $('#euStatus').val() } })
     .done(function () { bootstrap.Modal.getInstance('#editUserModal').hide(); table.ajax.reload(); })
     .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Failed', 'error'); });
});
</script>
@endpush
