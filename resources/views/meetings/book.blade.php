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

.step-section { border-top: 1px dashed var(--border); padding-top: 1rem; margin-top: 1rem; }
.step-label {
    font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: var(--text3); margin-bottom: .5rem;
}
.slot-grid { display: flex; flex-wrap: wrap; gap: 6px; max-height: 230px; overflow-y: auto; padding: 2px; }
.slot-btn {
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text2);
    border-radius: 7px;
    padding: 6px 10px;
    font-size: .74rem;
    font-weight: 500;
    cursor: pointer;
    transition: all .1s;
}
.slot-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
.slot-btn.selected { background: var(--primary); border-color: var(--primary); color: #fff; }
.slot-btn:disabled {
    background: var(--surface2);
    color: var(--text3);
    text-decoration: line-through;
    cursor: not-allowed;
    opacity: .6;
}
.selected-time-badge {
    background: rgba(var(--primary-rgb),.08);
    border: 1px solid rgba(var(--primary-rgb),.25);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: .82rem;
    color: var(--text);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
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

            {{-- ── Step 1: Client + Assigned Staff ── --}}
            <div class="step-label">Step 1 &middot; Who is this for?</div>
            <div class="row g-3">
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

                <div class="col-12">
                    <label class="form-label fw-semibold">Assigned Staff <span class="text-danger">*</span></label>
                    <select id="bookAssignedTo" class="form-select" style="width:100%">
                        <option value="">Select staff member…</option>
                        @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                    <div style="font-size:.68rem;color:var(--text3);margin-top:4px"><i class="bi bi-info-circle me-1"></i>The calendar below shows this person's free/busy times.</div>
                </div>
            </div>

            {{-- ── Step 2: Duration, Date, Slot grid ── --}}
            <div class="step-section d-none" id="step2Section">
                <div class="step-label">Step 2 &middot; Pick a date &amp; time</div>
                <div class="row g-3">
                    <div class="col-md-5">
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
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" id="bookDate" class="form-control">
                    </div>
                </div>

                <div class="mt-3">
                    <div id="slotEmptyMsg" class="text-muted small">Pick a date to see available times.</div>
                    <div id="slotLoading" class="text-muted small d-none"><span class="spinner-border spinner-border-sm me-1"></span>Checking availability…</div>
                    <div id="slotGrid" class="slot-grid"></div>
                </div>
            </div>

            {{-- ── Step 3: Details (unlocked after a slot is picked) ── --}}
            <div class="step-section d-none" id="step3Section">
                <div class="step-label">Step 3 &middot; Meeting details</div>

                <div class="selected-time-badge mb-3">
                    <span><i class="bi bi-calendar-check me-2"></i><strong id="selectedTimeText"></strong></span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSlotSelection()">Change time</button>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Meeting Title <span class="text-danger">*</span></label>
                        <input type="text" id="bookTitle" class="form-control" placeholder="e.g. Initial Consultation, Project Review…">
                    </div>

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

                    <div class="col-12">
                        <label class="form-label fw-semibold">Agenda</label>
                        <textarea id="bookAgenda" class="form-control" rows="3" placeholder="What will be discussed in this meeting?"></textarea>
                    </div>

                    <div id="conflictAlert" class="conflict-alert col-12">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i><span id="conflictMsg"></span>
                    </div>

                    <div class="col-12 d-flex gap-2 pt-2 border-top mt-2">
                        <button id="bookSubmitBtn" class="btn btn-primary">
                            <i class="bi bi-calendar-check me-1"></i>Book Meeting
                        </button>
                        <button type="button" onclick="resetBookForm()" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Start Over
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
var selectedSlot = null; // { date: 'YYYY-MM-DD', time: 'HH:mm', label: '...' }

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
            var statusCls = { Running: 'spill-running', Warning: 'spill-warning', Completed: 'spill-completed', Hold: 'spill-hold', Cancelled: 'spill-cancelled', Terminated: 'spill-terminated' };
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
        checkStep1Ready();
    });
    $('#bookClientId').on('select2:unselect', function() {
        $('#clientPreview').addClass('d-none');
        checkStep1Ready();
    });
    $('#bookAssignedTo').on('change', checkStep1Ready);

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
        checkStep1Ready();
    })();
    @endif

    // Default date to today for convenience once step 2 unlocks
    var today = new Date();
    var iso = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    $('#bookDate').val(iso).attr('min', iso);
});

// ── Step 1 → Step 2 gating ─────────────────────────────────────────────────────
function checkStep1Ready() {
    var ready = !!$('#bookClientId').val() && !!$('#bookAssignedTo').val();
    $('#step2Section').toggleClass('d-none', !ready);
    if (!ready) {
        $('#step3Section').addClass('d-none');
        selectedSlot = null;
    } else {
        loadAvailability();
    }
}

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

// ── Availability grid ──────────────────────────────────────────────────────────
var availabilityTimer;
$('#bookDuration, #bookDate').on('change', function() {
    clearSlotSelection();
    clearTimeout(availabilityTimer);
    availabilityTimer = setTimeout(loadAvailability, 200);
});

function loadAvailability() {
    var assignedTo = $('#bookAssignedTo').val();
    var date       = $('#bookDate').val();
    var duration   = $('#bookDuration').val();

    if (!assignedTo || !date || !duration) return;

    $('#slotEmptyMsg').addClass('d-none');
    $('#slotLoading').removeClass('d-none');
    $('#slotGrid').empty();

    $.post('{{ route("meetings.availability") }}', {
        assigned_to:      assignedTo,
        date:             date,
        duration_minutes: duration,
    })
    .done(function(r) {
        $('#slotLoading').addClass('d-none');
        if (!r.slots.length) {
            $('#slotEmptyMsg').removeClass('d-none').text('No slots fit in business hours for this duration.');
            return;
        }
        var html = '';
        r.slots.forEach(function(s) {
            html += '<button type="button" class="slot-btn" data-time="' + s.time + '" data-label="' + s.label + '" ' + (s.available ? '' : 'disabled') + '>' + s.label + '</button>';
        });
        $('#slotGrid').html(html);
    })
    .fail(function() {
        $('#slotLoading').addClass('d-none');
        $('#slotEmptyMsg').removeClass('d-none').text('Could not load availability. Try a different date.');
    });
}

$(document).on('click', '.slot-btn:not(:disabled)', function() {
    $('.slot-btn').removeClass('selected');
    $(this).addClass('selected');
    selectedSlot = {
        date:  $('#bookDate').val(),
        time:  $(this).data('time'),
        label: $(this).data('label'),
    };
    var dateLabel = new Date($('#bookDate').val() + 'T00:00:00').toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    $('#selectedTimeText').text(dateLabel + ' at ' + selectedSlot.label);
    $('#conflictAlert').removeClass('show');
    $('#step3Section').removeClass('d-none');
    $('html,body').animate({ scrollTop: $('#step3Section').offset().top - 20 }, 250);
});

function clearSlotSelection() {
    selectedSlot = null;
    $('.slot-btn').removeClass('selected');
    $('#step3Section').addClass('d-none');
}

// ── Submit ────────────────────────────────────────────────────────────────────
$('#bookSubmitBtn').on('click', function() {
    var clientId    = $('#bookClientId').val();
    var assignedTo  = $('#bookAssignedTo').val();
    var title       = $('#bookTitle').val().trim();

    if (!clientId)     { Swal.fire('Required', 'Please select a client.', 'warning'); return; }
    if (!assignedTo)   { Swal.fire('Required', 'Please select an assigned staff member.', 'warning'); return; }
    if (!selectedSlot) { Swal.fire('Required', 'Please pick an available time slot.', 'warning'); return; }
    if (!title)        { Swal.fire('Required', 'Please enter a meeting title.', 'warning'); return; }

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
            scheduled_at:     selectedSlot.date + ' ' + selectedSlot.time,
            duration_minutes: $('#bookDuration').val(),
            location:         $('#bookLocation').val().trim(),
            assigned_to:      assignedTo,
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
    })
    .fail(function(r) {
        if (r.status === 422 && r.responseJSON && r.responseJSON.message && !r.responseJSON.errors) {
            $('#conflictMsg').text(r.responseJSON.message);
            $('#conflictAlert').addClass('show');
            loadAvailability();
            return;
        }
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
    $('#bookAssignedTo').val('').trigger('change');
    $('#bookTitle,#bookLocation,#bookAgenda').val('');
    $('#bookDuration').val('60');
    $('#bookType').val('in_person');
    $('.type-card').removeClass('selected');
    $('.type-card[data-type="in_person"]').addClass('selected');
    $('#bookLocationWrap').removeClass('d-none');
    $('#bookLinkWrap').addClass('d-none');
    $('#clientPreview').addClass('d-none');
    $('#conflictAlert').removeClass('show');
    $('#slotGrid').empty();
    $('#slotEmptyMsg').removeClass('d-none').text('Pick a date to see available times.');
    clearSlotSelection();
    $('#step2Section').addClass('d-none');
}
</script>
@endpush
