@extends('layouts.app')
@section('title', 'Book a Meeting')

@push('styles')
<style>
.book-card {
    max-width: 760px;
    margin: 0 auto;
}
.conflict-alert {
    background: var(--c-red-bg);
    border: 1px solid var(--c-red-bg);
    border-radius: 8px;
    color: var(--c-red);
    padding: 10px 14px;
    font-size: .8rem;
    display: none;
}
.conflict-alert.show { display: block; }
.available-alert {
    background: rgba(5,150,105,.07);
    border: 1px solid rgba(5,150,105,.3);
    border-radius: 8px;
    color: #059669;
    padding: 10px 14px;
    font-size: .8rem;
    display: none;
}
.available-alert.show { display: block; }
.type-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 14px;
    cursor: pointer;
    text-align: center;
    transition: all .15s;
    background: var(--surface);
}
.type-card:hover, .type-card.selected {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb),.06);
}
.type-card .type-icon { font-size: 1.3rem; color: var(--text3); }
.type-card.selected .type-icon { color: var(--primary); }
.type-card .type-label { font-size: .72rem; font-weight: 600; color: var(--text2); margin-top: 4px; }
.type-card.selected .type-label { color: var(--primary); }
</style>
@endpush

@section('content')

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('meetings.all') }}" class="btn btn-sm btn-light border"><i class="bi bi-arrow-left"></i></a>
    <div>
        <h4 class="page-title mb-0">Book a Meeting</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">{{ $scheduledCount }} meeting{{ $scheduledCount !== 1 ? 's' : '' }} currently scheduled</div>
    </div>
</div>

<div class="book-card">
    <div class="card">
        <div class="card-body p-4">
            <div id="bookSuccessMsg" class="alert d-none mb-3 c-green" style="background:var(--c-green-bg);border:1px solid var(--c-green-bg);border-radius:8px;padding:14px 18px"></div>

            <div class="row g-3">
                {{-- Client Select --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
                    <select id="bookClientId" class="form-select" style="width:100%">
                        <option value="">Search and select a client…</option>
                    </select>
                    <div id="clientPreview" class="d-none mt-2 p-2 rounded d-flex align-items-center gap-2" style="background:rgba(var(--primary-rgb),.06);border:1px solid rgba(var(--primary-rgb),.15)">
                        <div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle fw-bold flex-shrink-0" style="width:32px;height:32px;font-size:.8rem" id="clientInitial"></div>
                        <div>
                            <div class="fw-semibold" style="font-size:.83rem;color:var(--text)" id="clientPreviewName"></div>
                            <div style="font-size:.72rem;color:var(--text3)" id="clientPreviewMeta"></div>
                        </div>
                    </div>
                </div>

                {{-- Title --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Meeting Title <span class="text-danger">*</span></label>
                    <input type="text" id="bookTitle" class="form-control" placeholder="e.g. Initial Consultation, Project Review…">
                </div>

                {{-- Type cards --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Meeting Type <span class="text-danger">*</span></label>
                    <div class="row g-2">
                        <div class="col-6 col-sm-3">
                            <div class="type-card selected" data-type="in_person">
                                <i class="bi bi-person-fill type-icon"></i>
                                <div class="type-label">In Person</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="type-card" data-type="phone">
                                <i class="bi bi-telephone-fill type-icon"></i>
                                <div class="type-label">Phone Call</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="type-card" data-type="video">
                                <i class="bi bi-camera-video-fill type-icon"></i>
                                <div class="type-label">Video Call</div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="type-card" data-type="online">
                                <i class="bi bi-globe type-icon"></i>
                                <div class="type-label">Online</div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" id="bookType" value="in_person">
                </div>

                {{-- Date, Time, Duration --}}
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Date & Time <span class="text-danger">*</span></label>
                    <input type="datetime-local" id="bookDatetime" class="form-control" step="300">
                    <div style="font-size:.68rem;color:var(--text3);margin-top:4px"><i class="bi bi-info-circle me-1"></i>Use the calendar to pick date and time</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Duration</label>
                    <select id="bookDuration" class="form-select">
                        <option value="15">15 minutes</option>
                        <option value="30">30 minutes</option>
                        <option value="45">45 minutes</option>
                        <option value="60" selected>1 hour</option>
                        <option value="90">1.5 hours</option>
                        <option value="120">2 hours</option>
                        <option value="180">3 hours</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="w-100">
                        <div id="conflictAlert" class="conflict-alert">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="conflictMsg"></span>
                        </div>
                        <div id="availableAlert" class="available-alert">
                            <i class="bi bi-check-circle-fill me-1"></i>Time slot is free
                        </div>
                    </div>
                </div>

                {{-- Assigned Staff --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Assigned Staff</label>
                    <select id="bookAssignedTo" class="form-select" style="width:100%">
                        <option value="">Unassigned</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Location / Link (conditional) --}}
                <div class="col-12" id="bookLocationWrap">
                    <label class="form-label fw-semibold">Location</label>
                    <input type="text" id="bookLocation" class="form-control" placeholder="Office address, room name…">
                </div>
                <div class="col-12 d-none" id="bookLinkWrap">
                    <label class="form-label fw-semibold">Join Link</label>
                    <div class="d-flex align-items-center gap-2 p-2 rounded" style="background:var(--surface2);border:1px solid var(--border);color:var(--text3)">
                        <i class="bi bi-camera-video"></i>
                        <span>A Google Meet link is generated automatically when saved</span>
                    </div>
                </div>

                {{-- Agenda --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Agenda</label>
                    <textarea id="bookAgenda" class="form-control" rows="3" placeholder="What will be discussed in this meeting?"></textarea>
                </div>

                {{-- Submit --}}
                <div class="col-12 d-flex gap-2 pt-2 border-top mt-2">
                    <button id="bookSubmitBtn" class="btn btn-primary">
                        <i class="bi bi-calendar-check me-1"></i>Book Meeting
                    </button>
                    <button type="button" onclick="resetBookForm()" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
$(function() {
    $('#bookAssignedTo').select2({ theme: 'bootstrap-5', width: '100%' });

    // ── Select2 client search ─────────────────────────────────────────────────
    $('#bookClientId').select2({
        theme: 'bootstrap-5',
        placeholder: 'Type client name, DFID, or brand…',
        allowClear: true,
        minimumInputLength: 1,
        ajax: {
            url: '/search',
            dataType: 'json',
            delay: 300,
            data: function(params) { return { q: params.term }; },
            processResults: function(data) {
                return {
                    results: data.map(function(c) {
                        return {
                            id:   c.id,
                            text: c.client_name,
                            dfid: c.dfid_number,
                            brand: c.brand_name,
                            status: c.client_status,
                            category: c.category,
                        };
                    })
                };
            },
            cache: true
        },
        templateResult: function(c) {
            if (!c.id) return c.text;
            var statusCls = { Running: 'spill-running', Warning: 'spill-warning', Completed: 'spill-completed', Hold: 'spill-hold', Cancelled: 'spill-cancelled' };
            var spill = statusCls[c.status] || 'spill-hold';
            return $('<div class="d-flex align-items-center gap-2 py-1">' +
                '<div class="d-flex align-items-center justify-content-center bg-primary text-white rounded-circle fw-bold flex-shrink-0" style="width:28px;height:28px;font-size:.72rem">' + (c.text ? c.text.charAt(0).toUpperCase() : '?') + '</div>' +
                '<div class="flex-grow-1 min-w-0">' +
                    '<div class="fw-semibold" style="font-size:.8rem">' + $('<div>').text(c.text).html() + '</div>' +
                    '<div style="font-size:.7rem;color:#6b7280">' + $('<div>').text(c.dfid || '').html() + (c.brand ? ' · ' + $('<div>').text(c.brand).html() : '') + '</div>' +
                '</div>' +
                '<span class="spill ' + spill + '" style="font-size:.6rem;flex-shrink:0">' + (c.status || '') + '</span>' +
            '</div>');
        },
        templateSelection: function(c) { return c.text || c.id; },
    });

    $('#bookClientId').on('select2:select', function(e) {
        var d = e.params.data;
        $('#clientPreview').removeClass('d-none');
        $('#clientInitial').text(d.text ? d.text.charAt(0).toUpperCase() : '?');
        $('#clientPreviewName').text(d.text);
        $('#clientPreviewMeta').text((d.dfid || '') + (d.brand ? ' · ' + d.brand : '') + (d.category ? ' · ' + d.category : ''));
        checkConflict();
    });
    $('#bookClientId').on('select2:unselect', function() {
        $('#clientPreview').addClass('d-none');
        $('#conflictAlert,#availableAlert').removeClass('show');
    });

    @if(!empty($preselectedClient))
    (function() {
        var c = {
            id:       {{ $preselectedClient->id }},
            text:     @json($preselectedClient->client_name),
            dfid:     @json($preselectedClient->dfid_number),
            brand:    @json($preselectedClient->brand_name),
            status:   @json($preselectedClient->client_status),
            category: @json($preselectedClient->category?->name),
        };
        var option = new Option(c.text, c.id, true, true);
        $('#bookClientId').append(option).trigger('change');
        $('#clientPreview').removeClass('d-none');
        $('#clientInitial').text(c.text ? c.text.charAt(0).toUpperCase() : '?');
        $('#clientPreviewName').text(c.text);
        $('#clientPreviewMeta').text((c.dfid || '') + (c.brand ? ' · ' + c.brand : '') + (c.category ? ' · ' + c.category : ''));
    })();
    @endif
});

// ── Type card selection ───────────────────────────────────────────────────────
$(document).on('click', '.type-card', function() {
    $('.type-card').removeClass('selected');
    $(this).addClass('selected');
    var type = $(this).data('type');
    $('#bookType').val(type);
    var needsLink = ['video', 'online'].indexOf(type) !== -1;
    $('#bookLinkWrap').toggleClass('d-none', !needsLink);
    $('#bookLocationWrap').toggleClass('d-none', needsLink);
});

// ── Conflict check ────────────────────────────────────────────────────────────
var conflictTimer;
function checkConflict() {
    clearTimeout(conflictTimer);
    var clientId = $('#bookClientId').val();
    var dt       = $('#bookDatetime').val();
    var dur      = $('#bookDuration').val();
    if (!clientId || !dt || !dur) {
        $('#conflictAlert,#availableAlert').removeClass('show');
        return;
    }
    conflictTimer = setTimeout(function() {
        $.post('/meetings/check-conflict', {
            client_id:        clientId,
            scheduled_at:     dt,
            duration_minutes: dur,
        })
        .done(function(r) {
            if (r.conflict) {
                $('#conflictMsg').text('"' + r.title + '" at ' + r.time);
                $('#conflictAlert').addClass('show');
                $('#availableAlert').removeClass('show');
            } else {
                $('#conflictAlert').removeClass('show');
                $('#availableAlert').addClass('show');
            }
        });
    }, 500);
}
$('#bookDatetime, #bookDuration').on('change input', checkConflict);

// ── Submit ────────────────────────────────────────────────────────────────────
$('#bookSubmitBtn').on('click', function() {
    var clientId = $('#bookClientId').val();
    var title    = $('#bookTitle').val().trim();
    var dt       = $('#bookDatetime').val();

    if (!clientId) { Swal.fire('Required', 'Please select a client.', 'warning'); return; }
    if (!title)    { Swal.fire('Required', 'Please enter a meeting title.', 'warning'); return; }
    if (!dt)       { Swal.fire('Required', 'Please select a date and time.', 'warning'); return; }

    var $btn = $(this);
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Booking…');

    $.ajax({
        url:     '/meetings/book',
        type:    'POST',
        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
        data: {
            client_id:        clientId,
            title:            title,
            type:             $('#bookType').val(),
            scheduled_at:     dt,
            duration_minutes: $('#bookDuration').val(),
            location:         $('#bookLocation').val().trim(),
            assigned_to:      $('#bookAssignedTo').val() || '',
            agenda:           $('#bookAgenda').val().trim(),
        }
    })
    .done(function(r) {
        $('#bookSuccessMsg').removeClass('d-none').html(
            '<i class="bi bi-check-circle-fill me-2"></i>' +
            'Meeting booked successfully for <strong>' + $('<div>').text(r.client_name).html() + '</strong>! ' +
            '<a href="' + r.client_url + '?tab=meetings" class="ms-2 fw-semibold c-green">View in client profile →</a>'
        );
        $('html,body').animate({ scrollTop: 0 }, 300);
        resetBookForm();
        $('#conflictAlert,#availableAlert').removeClass('show');
    })
    .fail(function(r) {
        var msg = r.responseJSON && r.responseJSON.errors
            ? Object.values(r.responseJSON.errors).flat().join('<br>')
            : (r.responseJSON && r.responseJSON.message ? r.responseJSON.message : 'Failed to book meeting');
        Swal.fire({ icon: 'error', title: 'Error', html: msg });
    })
    .always(function() {
        $btn.prop('disabled', false).html('<i class="bi bi-calendar-check me-1"></i>Book Meeting');
    });
});

function resetBookForm() {
    $('#bookClientId').val(null).trigger('change');
    $('#bookTitle,#bookLocation,#bookAgenda').val('');
    $('#bookAssignedTo').val('').trigger('change');
    $('#bookDatetime').val('');
    $('#bookDuration').val('60');
    $('#bookType').val('in_person');
    $('.type-card').removeClass('selected');
    $('.type-card[data-type="in_person"]').addClass('selected');
    $('#bookLocationWrap').removeClass('d-none');
    $('#bookLinkWrap').addClass('d-none');
    $('#clientPreview').addClass('d-none');
    $('#conflictAlert,#availableAlert').removeClass('show');
}
</script>
@endpush
