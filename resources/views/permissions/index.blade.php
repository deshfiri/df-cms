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

{{-- ── Per-user grants ──────────────────────────────────────────────────
     Roles stay the normal way to hand out access. This is for the exceptions:
     one person who needs one extra thing, without inventing a role for them. --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header py-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-person-gear me-2"></i>Assign permissions to a user</h6>
        <small class="text-muted">Grants added here apply to that person only, on top of whatever their roles already allow.</small>
    </div>
    <div class="card-body">
        <label class="form-label small fw-semibold">User</label>
        <select id="permUser" class="form-select form-select-sm" style="width:100%">
            <option value="">Select a user…</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}">{{ $u->name }} — {{ $u->email }}</option>
            @endforeach
        </select>

        <div id="permUserPanel" class="d-none mt-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                <div style="font-size:.78rem;color:var(--text2)">
                    Roles: <span id="permUserRoles" class="fw-semibold"></span>
                </div>
                <div style="font-size:.72rem;color:var(--text3)">
                    <i class="bi bi-lock-fill me-1"></i>Greyed items come from a role and can only be changed on the role.
                </div>
            </div>

            <div id="permSuperAdminNote" class="alert alert-info py-2 px-3 d-none" style="font-size:.8rem">
                <i class="bi bi-info-circle me-1"></i>
                This user is a <strong>Super Admin</strong> and already passes every permission check.
                Changes here have no practical effect.
            </div>

            <div id="permUserGrid" style="max-height:420px;overflow-y:auto"></div>

            <div class="d-flex align-items-center gap-2 mt-3 pt-3 border-top">
                <button class="btn btn-primary btn-sm" id="permUserSave">
                    <i class="bi bi-check2 me-1"></i>Save permissions
                </button>
                <span id="permUserDirty" class="d-none" style="font-size:.75rem;color:var(--c-yellow)">
                    <i class="bi bi-dot"></i>Unsaved changes
                </span>
            </div>
        </div>
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

// ── Per-user permission grants ────────────────────────────────────────────
// Every permission in the system, grouped the same way the cards below are.
const ALL_PERMISSIONS = @json($permissionGroups);

let permUserState = null;

$('#permUser').select2({ theme: 'bootstrap-5', width: '100%' });

$('#permUser').on('change', function () {
    const id = $(this).val();
    if (!id) { $('#permUserPanel').addClass('d-none'); permUserState = null; return; }

    $.get('/permissions/users/' + id)
        .done(function (r) {
            permUserState = r;
            renderUserPermissions();
            $('#permUserPanel').removeClass('d-none');
            $('#permUserDirty').addClass('d-none');
        })
        .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not load that user.', 'error'));
});

function renderUserPermissions() {
    const direct   = new Set(permUserState.direct);
    const viaRoles = new Set(permUserState.via_roles);

    $('#permUserRoles').text(permUserState.roles.length ? permUserState.roles.join(', ') : 'none');
    $('#permSuperAdminNote').toggleClass('d-none', !permUserState.is_super_admin);

    let html = '';
    ALL_PERMISSIONS.forEach(function (group) {
        html += '<div class="mb-3">'
              + '<div style="font-size:.66rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:.35rem">'
              + $('<div>').text(group.category).html() + '</div>'
              + '<div class="d-flex flex-wrap gap-2">';

        group.items.forEach(function (p) {
            const isDirect = direct.has(p.name);
            const fromRole = viaRoles.has(p.name);

            // Locked only when a role is the *sole* source: there is nothing on
            // this screen to change. If it is also a direct grant it stays
            // editable, otherwise saving would silently drop that grant and the
            // user would lose the permission the day the role changed.
            const locked  = fromRole && !isDirect;
            const checked = fromRole || isDirect;
            const id      = 'perm_' + p.name.replace(/[^a-z0-9]/gi, '_');

            const border = locked ? 'var(--border)' : (checked ? 'var(--primary)' : 'var(--border)');
            const tip    = locked ? 'Granted by a role' : (fromRole ? 'Also granted by a role' : '');

            html += '<label for="' + id + '" class="d-flex align-items-center gap-1 py-1 px-2" '
                  + 'style="background:var(--surface2);border:1px solid ' + border + ';'
                  + 'border-radius:6px;font-size:.78rem;cursor:' + (locked ? 'not-allowed' : 'pointer') + ';'
                  + 'color:' + (locked ? 'var(--text3)' : 'var(--text2)') + '">'
                  + '<input type="checkbox" class="form-check-input mt-0 perm-toggle" id="' + id + '" '
                  + 'data-name="' + $('<div>').text(p.name).html() + '" '
                  + (checked ? 'checked ' : '') + (locked ? 'disabled ' : '') + '>'
                  + $('<div>').text(p.label).html()
                  + (fromRole ? ' <i class="bi bi-lock-fill" title="' + tip + '" style="font-size:.6rem"></i>' : '')
                  + '</label>';
        });

        html += '</div></div>';
    });

    $('#permUserGrid').html(html);
}

$(document).on('change', '.perm-toggle', function () {
    $('#permUserDirty').removeClass('d-none');
});

$('#permUserSave').on('click', function () {
    if (!permUserState) return;

    // Only the unlocked boxes are sent: disabled inputs are role-derived and
    // are not direct grants.
    const permissions = $('.perm-toggle:checked').not(':disabled').map(function () {
        return $(this).data('name');
    }).get();

    const $btn = $(this).prop('disabled', true);

    $.post('/permissions/users/' + permUserState.user.id, { permissions, _token: csrf })
        .done(function (r) {
            permUserState.direct = r.direct;
            $('#permUserDirty').addClass('d-none');
            Swal.fire({ icon: 'success', title: 'Permissions saved', timer: 1300, showConfirmButton: false });
        })
        .fail(x => Swal.fire('Error', x.responseJSON?.message || 'Could not save permissions.', 'error'))
        .always(() => $btn.prop('disabled', false));
});

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
