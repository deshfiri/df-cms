@extends('layouts.app')
@section('title', 'Role Management')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-shield-lock me-2"></i>Role Management</h4>
    <div class="d-flex gap-2">
        <a href="{{ route('permissions.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-key me-1"></i>Manage Permissions
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createRoleModal">
            <i class="bi bi-plus-lg me-1"></i>New Role
        </button>
    </div>
</div>

<div class="row g-3">
    @foreach($roles as $role)
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100" id="role-card-{{ $role->id }}">
            <div class="card-header d-flex align-items-center justify-content-between py-3">
                <div>
                    <h6 class="fw-bold mb-0" id="role-name-{{ $role->id }}">{{ $role->name }}</h6>
                    <small class="text-muted">{{ $role->users_count }} user{{ $role->users_count != 1 ? 's' : '' }} · {{ $role->permissions->count() }} permission{{ $role->permissions->count() != 1 ? 's' : '' }}</small>
                </div>
                <div class="d-flex gap-1">
                    @if($role->name !== 'Super Admin')
                    <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Clone" onclick="cloneRole({{ $role->id }}, '{{ $role->name }}')">
                        <i class="bi bi-copy"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Rename" onclick="renameRole({{ $role->id }}, '{{ $role->name }}')">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Delete" onclick="deleteRole({{ $role->id }}, {{ $role->users_count }})">
                        <i class="bi bi-trash"></i>
                    </button>
                    @else
                    <span class="spill spill-warning">System</span>
                    @endif
                </div>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-2 fw-semibold">Permissions:</p>
                <div class="permission-matrix" data-role="{{ $role->id }}">
                    @foreach($permissions as $category => $perms)
                    <div class="mb-3">
                        <div class="text-uppercase small fw-bold text-muted mb-1" style="font-size:.7rem;letter-spacing:.05em">{{ $category }}</div>
                        <div class="d-flex flex-wrap gap-1">
                            @foreach($perms as $perm)
                            @php $permLabel = explode(':', $perm->name)[1] ?? $perm->name; @endphp
                            <div class="form-check form-check-inline m-0">
                                <input class="form-check-input perm-check" type="checkbox"
                                    id="perm-{{ $role->id }}-{{ $perm->id }}"
                                    data-role="{{ $role->id }}"
                                    data-perm="{{ $perm->id }}"
                                    value="{{ $perm->id }}"
                                    {{ $role->permissions->contains($perm) ? 'checked' : '' }}
                                    {{ $role->name === 'Super Admin' ? 'disabled' : '' }}>
                                <label class="form-check-label small" for="perm-{{ $role->id }}-{{ $perm->id }}" style="font-size:.78rem">
                                    {{ $permLabel }}
                                </label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @if($role->name !== 'Super Admin')
                <div class="d-flex gap-2 mt-2 pt-2 border-top">
                    <button class="btn btn-sm btn-primary flex-fill" onclick="savePermissions({{ $role->id }})">
                        <i class="bi bi-save me-1"></i>Save Permissions
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleAll({{ $role->id }}, true)">All</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleAll({{ $role->id }}, false)">None</button>
                </div>
                @else
                <div class="alert alert-warning py-1 px-2 mb-0 mt-2" style="font-size:.78rem">
                    <i class="bi bi-shield-fill-check me-1"></i>Super Admin bypasses all permission checks.
                </div>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Create Role Modal --}}
<div class="modal fade" id="createRoleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold">New Role</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold">Role Name</label>
                <input type="text" class="form-control form-control-sm" id="newRoleName" placeholder="e.g. Content Manager">
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-sm btn-primary w-100" onclick="createRole()">
                    <i class="bi bi-plus-lg me-1"></i>Create Role
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const csrf = $('meta[name=csrf-token]').attr('content');

function createRole() {
    const name = $('#newRoleName').val().trim();
    if (!name) return;
    $.post('{{ route("roles.store") }}', { name, _token: csrf })
     .done(() => { Swal.fire({ icon:'success', title:'Role created', timer:1500, showConfirmButton:false }).then(() => location.reload()); })
     .fail(r => Swal.fire('Error', r.responseJSON?.errors?.name?.[0] || 'Failed', 'error'));
}

function renameRole(id, current) {
    Swal.fire({
        title: 'Rename Role',
        input: 'text',
        inputValue: current,
        inputAttributes: { maxlength: 100 },
        showCancelButton: true,
        confirmButtonText: 'Save',
    }).then(r => {
        if (!r.isConfirmed || !r.value.trim()) return;
        $.ajax({ url: `/roles/${id}`, type: 'PUT', data: { name: r.value.trim(), _token: csrf } })
         .done(() => { $(`#role-name-${id}`).text(r.value.trim()); Swal.fire({ icon:'success', timer:1200, showConfirmButton:false }); })
         .fail(x => Swal.fire('Error', x.responseJSON?.errors?.name?.[0] || x.responseJSON?.message || 'Failed', 'error'));
    });
}

function deleteRole(id, userCount) {
    if (userCount > 0) {
        return Swal.fire('Cannot Delete', `This role has ${userCount} user(s) assigned. Reassign them first.`, 'warning');
    }
    Swal.fire({ title: 'Delete Role?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545' })
     .then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url: `/roles/${id}`, type: 'DELETE', data: { _token: csrf } })
         .done(() => { $(`#role-card-${id}`).closest('.col-md-6').remove(); Swal.fire({ icon:'success', timer:1200, showConfirmButton:false }); })
         .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error'));
     });
}

function cloneRole(id, name) {
    Swal.fire({
        title: 'Clone Role',
        text: `Cloning permissions from "${name}"`,
        input: 'text',
        inputPlaceholder: 'New role name',
        showCancelButton: true,
        confirmButtonText: 'Clone',
    }).then(r => {
        if (!r.isConfirmed || !r.value.trim()) return;
        $.post(`/roles/${id}/clone`, { name: r.value.trim(), _token: csrf })
         .done(() => { Swal.fire({ icon:'success', title:'Role cloned', timer:1500, showConfirmButton:false }).then(() => location.reload()); })
         .fail(x => Swal.fire('Error', x.responseJSON?.errors?.name?.[0] || 'Failed', 'error'));
    });
}

function toggleAll(roleId, checked) {
    $(`.perm-check[data-role="${roleId}"]`).prop('checked', checked);
}

function savePermissions(roleId) {
    const ids = [];
    $(`.perm-check[data-role="${roleId}"]:checked`).each(function () { ids.push($(this).val()); });
    $.post(`/roles/${roleId}/sync-permissions`, { permissions: ids, _token: csrf })
     .done(r => Swal.fire({ icon:'success', title:`${r.count} permission(s) saved`, timer:1500, showConfirmButton:false }))
     .fail(() => Swal.fire('Error', 'Could not save permissions', 'error'));
}

// Enter key on modal
$('#newRoleName').on('keydown', function (e) { if (e.key === 'Enter') createRole(); });
</script>
@endpush
