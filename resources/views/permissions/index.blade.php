@extends('layouts.app')
@section('title', 'Permission Management')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0"><i class="bi bi-key me-2"></i>Permission Management</h4>
    <div class="d-flex gap-2">
        <a href="{{ route('roles.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-shield-lock me-1"></i>Back to Roles
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createPermModal">
            <i class="bi bi-plus-lg me-1"></i>New Permission
        </button>
    </div>
</div>

@foreach($permissions as $category => $perms)
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 px-3">
        <h6 class="fw-bold mb-0" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text3)">{{ $category }}</h6>
    </div>
    <div class="card-body p-3">
        <div class="d-flex flex-wrap gap-2">
            @foreach($perms as $perm)
            @php $label = explode(':', $perm->name)[1] ?? $perm->name; @endphp
            <div class="d-flex align-items-center gap-1 py-1 px-2" style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;font-size:.78rem;color:var(--text2)">
                <i class="bi bi-key-fill" style="color:var(--text3);font-size:.7rem"></i>
                {{ $label }}
                <button class="btn p-0 ms-1 d-flex align-items-center" style="color:var(--text3);border:none;background:none;font-size:.6rem;line-height:1" title="Delete" onclick="deletePerm({{ $perm->id }}, '{{ $perm->name }}', this)">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endforeach

@if($permissions->isEmpty())
<div class="text-center py-5 text-muted">
    <i class="bi bi-key fs-1 d-block mb-2 opacity-25"></i>
    No permissions defined yet.
</div>
@endif

{{-- Create Permission Modal --}}
<div class="modal fade" id="createPermModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold">New Permission</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Category <span class="text-muted fw-normal">(optional prefix)</span></label>
                    <input type="text" class="form-control form-control-sm" id="permCategory" placeholder="e.g. clients, payments, import">
                    <div class="form-text">Used to group permissions. Leave blank for General.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Permission Name</label>
                    <input type="text" class="form-control form-control-sm" id="permName" placeholder="e.g. create, view all, export">
                </div>
                <div class="alert alert-info py-1 px-2 mb-0" style="font-size:.78rem" id="permPreview">
                    Preview: <strong id="permPreviewText">—</strong>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button class="btn btn-sm btn-primary w-100" onclick="createPerm()">
                    <i class="bi bi-plus-lg me-1"></i>Create Permission
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const csrf = $('meta[name=csrf-token]').attr('content');

function updatePreview() {
    const cat  = $('#permCategory').val().trim();
    const name = $('#permName').val().trim();
    $('#permPreviewText').text(cat && name ? `${cat}:${name}` : (name || '—'));
}
$('#permCategory, #permName').on('input', updatePreview);

function createPerm() {
    const category = $('#permCategory').val().trim();
    const name     = $('#permName').val().trim();
    if (!name) return Swal.fire('Validation', 'Permission name is required.', 'warning');

    $.post('{{ route("permissions.store") }}', { name, category, _token: csrf })
     .done(() => { Swal.fire({ icon:'success', title:'Permission created', timer:1500, showConfirmButton:false }).then(() => location.reload()); })
     .fail(r => Swal.fire('Error', r.responseJSON?.errors?.name?.[0] || r.responseJSON?.message || 'Failed', 'error'));
}

function deletePerm(id, name, btn) {
    Swal.fire({ title: `Delete "${name}"?`, text:'This removes it from all roles too.', icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545' })
     .then(r => {
        if (!r.isConfirmed) return;
        $.ajax({ url: `/permissions/${id}`, type: 'DELETE', data: { _token: csrf } })
         .done(() => { $(btn).closest('.badge').remove(); Swal.fire({ icon:'success', timer:1200, showConfirmButton:false }); })
         .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Failed', 'error'));
     });
}
</script>
@endpush
