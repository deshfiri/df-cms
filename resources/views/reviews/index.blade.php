@extends('layouts.app')
@section('title', 'Reviews & Reports')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-chat-square-text me-2"></i>Reviews & Reports</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Leave feedback about a colleague, a team, or the company &mdash; named or anonymous</div>
    </div>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newReviewModal">
        <i class="bi bi-plus-lg me-1"></i>New Review / Report
    </button>
</div>

<div class="card section-card mb-3">
    <div class="card-header py-3">
        <h6 class="fw-bold mb-0">My Reviews & Reports</h6>
        <div style="font-size:.7rem;color:var(--text3)">What you've submitted from this browser &mdash; including anonymous ones, which no one else (not even Super Admin) can trace back to you</div>
    </div>
    <div class="card-body p-0" id="myReviewList">
        <div class="text-center py-4 small" style="color:var(--text3)">Nothing submitted from this browser yet.</div>
    </div>
</div>

@can('view reviews')
<div class="card section-card">
    <div class="card-header py-3"><h6 class="fw-bold mb-0">Submitted Reviews & Reports</h6></div>
    <div class="card-body p-0" id="reviewList">
        <div class="text-center py-5">
            <div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div>
        </div>
    </div>
</div>
@else
<div class="card section-card">
    <div class="card-body text-center py-5" style="color:var(--text3)">
        <i class="bi bi-eye-slash" style="font-size:2rem"></i>
        <div class="mt-2" style="font-size:.82rem">You don't have permission to view submitted reviews yet. You can still post one above.</div>
    </div>
</div>
@endcan

{{-- New Review/Report Modal --}}
<div class="modal fade" id="newReviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h6 class="modal-title fw-bold">New Review / Report</h6>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Type</label>
                    <select id="rvType" class="form-select">
                        <option value="review">Review</option>
                        <option value="report">Report</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">About</label>
                    <select id="rvSubjectType" class="form-select">
                        <option value="general">General / Company-wide</option>
                        <option value="user">A specific colleague</option>
                        <option value="department">A team / department</option>
                    </select>
                </div>
                <div class="mb-3 d-none" id="rvUserWrap">
                    <label class="form-label fw-semibold small">Colleague</label>
                    <select id="rvSubjectUser" class="form-select">
                        <option value=""></option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3 d-none" id="rvDeptWrap">
                    <label class="form-label fw-semibold small">Team / Department</label>
                    <select id="rvSubjectDepartment" class="form-select">
                        <option value=""></option>
                        @foreach($departments as $d)
                        <option value="{{ $d }}">{{ $d }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
                    <input type="text" id="rvTitle" class="form-control" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Message <span class="text-danger">*</span></label>
                    <textarea id="rvMessage" class="form-control" rows="4" maxlength="5000"></textarea>
                </div>
                <div class="form-check">
                    <input type="checkbox" id="rvAnonymous" class="form-check-input">
                    <label class="form-check-label small" for="rvAnonymous">Post anonymously &mdash; your identity will not be shown or stored, not even to Super Admin</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-light" data-bs-dismiss="modal">Cancel</button>
                <button id="rvSubmit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const reviewsUrl = '{{ route('reviews.index') }}';
const reviewsMineUrl = '{{ route('reviews.mine') }}';
const reviewsDeleteBase = '{{ url('reviews') }}';
const canViewReviews = @json(auth()->user()->can('view reviews'));
const MY_TOKENS_KEY = 'dfcp_my_review_tokens';

function rvEsc(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function rvGetMyTokens() {
    try {
        return JSON.parse(localStorage.getItem(MY_TOKENS_KEY)) || [];
    } catch (e) {
        return [];
    }
}

function rvAddMyToken(token) {
    const tokens = rvGetMyTokens();
    tokens.push(token);
    localStorage.setItem(MY_TOKENS_KEY, JSON.stringify(tokens));
}

function loadMyReviews() {
    const tokens = rvGetMyTokens();
    if (!tokens.length) {
        $('#myReviewList').html('<div class="text-center py-4 small" style="color:var(--text3)">Nothing submitted from this browser yet.</div>');
        return;
    }

    $.post(reviewsMineUrl, { tokens: tokens })
    .done(function (res) {
        const items = res.data || [];
        if (!items.length) {
            $('#myReviewList').html('<div class="text-center py-4 small" style="color:var(--text3)">Nothing submitted from this browser yet.</div>');
            return;
        }

        let html = '';
        items.forEach(function (item) {
            const typeCls = item.type === 'report' ? 'spill-rejected' : 'spill-running';
            html += '<div class="p-3" style="border-bottom:1px solid var(--border)">'
                + '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">'
                + '<div><span class="spill ' + typeCls + ' me-2">' + rvEsc(item.type) + '</span><span class="fw-semibold">' + rvEsc(item.title) + '</span>'
                + (item.is_anonymous ? ' <span class="small" style="color:var(--text3)">(posted anonymously)</span>' : '') + '</div>'
                + '<span class="small" style="color:var(--text3)">' + rvEsc(item.created_at_human) + '</span>'
                + '</div>'
                + '<div style="font-size:.85rem;color:var(--text2);white-space:pre-wrap">' + rvEsc(item.message) + '</div>'
                + '</div>';
        });
        $('#myReviewList').html(html);
    });
}

$('#rvSubjectType').on('change', function () {
    const val = $(this).val();
    $('#rvUserWrap').toggleClass('d-none', val !== 'user');
    $('#rvDeptWrap').toggleClass('d-none', val !== 'department');
});

function resetReviewForm() {
    $('#rvType').val('review');
    $('#rvSubjectType').val('general').trigger('change');
    $('#rvSubjectUser').val('');
    $('#rvSubjectDepartment').val('');
    $('#rvTitle').val('');
    $('#rvMessage').val('');
    $('#rvAnonymous').prop('checked', false);
}

$('#newReviewModal').on('show.bs.modal', resetReviewForm);

$('#rvSubmit').on('click', function () {
    const title = $('#rvTitle').val().trim();
    const message = $('#rvMessage').val().trim();
    if (!title || !message) {
        Swal.fire('Required', 'Please fill in both Title and Message.', 'warning');
        return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true);
    $.post(reviewsUrl, {
        type: $('#rvType').val(),
        subject_type: $('#rvSubjectType').val(),
        subject_user_id: $('#rvSubjectUser').val(),
        subject_department: $('#rvSubjectDepartment').val(),
        title: title,
        message: message,
        is_anonymous: $('#rvAnonymous').is(':checked') ? 1 : 0,
    })
    .done(function (res) {
        bootstrap.Modal.getInstance('#newReviewModal').hide();
        if (res.poster_token) rvAddMyToken(res.poster_token);
        Swal.fire({ icon: 'success', title: 'Submitted', timer: 1400, showConfirmButton: false });
        loadMyReviews();
        if (canViewReviews) loadReviews();
    })
    .fail(function (r) {
        const msg = r.responseJSON?.errors ? Object.values(r.responseJSON.errors).flat().join('<br>') : (r.responseJSON?.message || 'Could not submit.');
        Swal.fire({ icon: 'error', title: 'Error', html: msg });
    })
    .always(function () { $btn.prop('disabled', false); });
});

@can('view reviews')
function loadReviews() {
    $('#reviewList').html('<div class="text-center py-5"><div class="spinner-border spinner-border-sm" style="color:var(--primary)"></div></div>');
    $.get(reviewsUrl)
    .done(function (res) {
        const items = res.data || [];
        if (!items.length) {
            $('#reviewList').html('<div class="text-center py-5" style="color:var(--text3)"><i class="bi bi-inbox" style="font-size:2rem"></i><div class="mt-2" style="font-size:.82rem">No reviews or reports yet.</div></div>');
            return;
        }

        let html = '';
        items.forEach(function (item) {
            const typeCls = item.type === 'report' ? 'spill-rejected' : 'spill-running';
            const about = item.subject_user_name ? ('Colleague: ' + rvEsc(item.subject_user_name))
                : (item.subject_department ? ('Team: ' + rvEsc(item.subject_department)) : 'General');
            const poster = item.is_anonymous ? '<em>Anonymous</em>' : rvEsc(item.posted_by_name || 'Unknown');

            html += '<div class="p-3" style="border-bottom:1px solid var(--border)">'
                + '<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">'
                + '<div><span class="spill ' + typeCls + ' me-2">' + rvEsc(item.type) + '</span><span class="fw-semibold">' + rvEsc(item.title) + '</span></div>'
                + '<div class="d-flex align-items-center gap-2">'
                + '<span class="small" style="color:var(--text3)">' + rvEsc(item.created_at_human) + '</span>'
                + '<button class="btn btn-sm px-2 py-1 rv-delete c-red" data-id="' + item.id + '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg)" title="Delete"><i class="bi bi-trash"></i></button>'
                + '</div>'
                + '</div>'
                + '<div class="small mb-2" style="color:var(--text3)">' + about + ' &middot; By ' + poster + '</div>'
                + '<div style="font-size:.85rem;color:var(--text2);white-space:pre-wrap">' + rvEsc(item.message) + '</div>'
                + '</div>';
        });
        $('#reviewList').html(html);
    })
    .fail(function () {
        $('#reviewList').html('<div class="text-center py-4 small c-red"><i class="bi bi-exclamation-circle me-1"></i>Failed to load reviews.</div>');
    });
}

$(document).on('click', '.rv-delete', function () {
    const id = $(this).data('id');
    Swal.fire({ title: 'Delete this review?', text: 'This cannot be undone.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Delete' })
    .then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: reviewsDeleteBase + '/' + id, type: 'DELETE' })
        .done(function () { loadReviews(); })
        .fail(function (x) { Swal.fire('Error', x.responseJSON?.message || 'Could not delete.', 'error'); });
    });
});

loadReviews();
@endcan

loadMyReviews();
</script>
@endpush
