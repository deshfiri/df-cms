@extends('layouts.app')
@section('title', ($departments->implode(' / ') ?: 'My') . ' Dashboard')

@section('content')
    @php $stageUser = auth()->user()->can('submit-stage') && !auth()->user()->hasRole(['Super Admin', 'Manager']); @endphp
    <div class="mb-3">
        <h4 class="page-title mb-0">{{ $stageUser ? 'My Work' : (($departments->implode(' / ') ?: 'My') . ' Team Dashboard') }}</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            {{ $stageUser ? 'Clients waiting on your stage — do the work, then Submit to send it to the next team.' : 'Showing only work assigned to your team' . ($departments->count() > 1 ? 's' : '') }}
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">{{ $pending->count() }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Awaiting Your
                    Team</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">{{ $completedThisWeek }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Completed
                    This Week</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">{{ $myTasks->count() }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">My Open Tasks
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-red">{{ $overdueTaskCount }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Overdue Tasks
                </div>
            </div>
        </div>
    </div>

    @unless($stageUser)
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">{{ $myAssignedClientCount }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Total
                    Assigned Clients</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-green">{{ $myActiveClientCount }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Active
                    Clients</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center py-3">
                <div class="fw-bold fs-4 mb-0 c-yellow">{{ $followUpsDueToday }}</div>
                <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Follow-up Due
                    Today</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Recently Assigned Clients</h6>
                </div>
                <div class="card-body p-0">
                    @forelse($recentlyAssignedClients as $c)
                        <a href="{{ route('clients.show', $c) }}"
                            class="d-flex align-items-center justify-content-between p-3 text-decoration-none"
                            style="border-bottom:1px solid var(--border)">
                            <span class="fw-semibold small" style="color:var(--text)">{{ $c->client_name }}</span>
                            <span class="small" style="color:var(--text3)">{{ $c->dfid_number }}</span>
                        </a>
                    @empty
                        <div class="text-center py-4 small" style="color:var(--text3)">No clients assigned yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Recently Transferred to Me</h6>
                </div>
                <div class="card-body p-0">
                    @forelse($recentlyTransferredToMe as $t)
                        <a href="{{ route('clients.show', $t->client_id) }}"
                            class="d-flex align-items-center justify-content-between p-3 text-decoration-none"
                            style="border-bottom:1px solid var(--border)">
                            <span class="fw-semibold small"
                                style="color:var(--text)">{{ $t->client->client_name ?? '—' }}</span>
                            <span class="small" style="color:var(--text3)">{{ $t->created_at->diffForHumans() }}</span>
                        </a>
                    @empty
                        <div class="text-center py-4 small" style="color:var(--text3)">No recent transfers.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    @endunless

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Clients Waiting on {{ $departments->implode(' / ') ?: 'Your Team' }}</h6>
                </div>
                <div class="card-body p-0">
                    @forelse($pending as $row)
                        <div class="d-flex align-items-center gap-3 p-3" style="border-bottom:1px solid var(--border)">
                            <div class="flex-grow-1">
                                <a href="{{ route('clients.show', $row->client_id) }}" class="fw-semibold small text-decoration-none" style="color:var(--text)">{{ $row->client->client_name ?? '—' }}</a>
                                <div style="font-size:.7rem;color:var(--text3);display:flex;flex-wrap:wrap;gap:.1rem .7rem;margin-top:1px">
                                    <span><i class="bi bi-diagram-3 me-1"></i>{{ $row->stage->name }}</span>
                                    @if($row->client?->brand_name)<span><i class="bi bi-tag me-1"></i>{{ $row->client->brand_name }}</span>@endif
                                    <span><i class="bi bi-hash"></i>{{ $row->client?->dfid_number ?? '—' }}</span>
                                    @if($row->client?->assignedUser)<span><i class="bi bi-person me-1"></i>{{ $row->client->assignedUser->name }}</span>@endif
                                </div>
                                @if($row->status === 'Need Revision' && $row->rejection_reason)
                                    <div style="font-size:.68rem;color:#dc3545;margin-top:2px"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ $row->rejection_reason }}</div>
                                @endif
                            </div>
                            @if($departments->count() > 1)
                                <span
                                    style="font-size:.68rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">{{ $row->stage->department }}</span>
                            @endif
                            @php $cls = ['Pending' => 'spill-pending', 'Submitted' => 'spill-submitted', 'Need Revision' => 'spill-need-revision'][$row->status] ?? 'spill-pending'; @endphp
                            <span class="spill {{ $cls }}">{{ $row->status }}</span>
                            @if($stageUser && in_array($row->status, ['Pending', 'Need Revision'], true))
                                <button class="btn btn-sm btn-primary py-1 px-2 stage-submit"
                                    data-client="{{ $row->client_id }}" data-stage="{{ $row->stage_id }}"
                                    data-client-name="{{ e($row->client->client_name ?? '—') }}" data-stage-name="{{ e($row->stage->name) }}"
                                    style="font-size:.72rem;white-space:nowrap"><i class="bi bi-send me-1"></i>Submit</button>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-5" style="color:var(--text3)">
                            <i class="bi bi-check2-circle" style="font-size:2rem"></i>
                            <div class="mt-2" style="font-size:.82rem">Nothing waiting on your team right now.</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">My Tasks</h6>
                </div>
                <div class="card-body p-0">
                    @forelse($myTasks as $task)
                        <div class="d-flex align-items-center gap-3 p-3" style="border-bottom:1px solid var(--border)">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small" style="color:var(--text)">{{ $task->title }}</div>
                                <div style="font-size:.72rem;color:var(--text3)">{{ $task->client->client_name ?? '—' }} · Due
                                    {{ $task->due_date?->format('d M Y') ?? '—' }}</div>
                            </div>
                            <span
                                class="spill {{ $task->is_overdue ? 'spill-rejected' : 'spill-pending' }}">{{ $task->is_overdue ? 'Overdue' : $task->status }}</span>
                        </div>
                    @empty
                        <div class="text-center py-5" style="color:var(--text3)">
                            <i class="bi bi-list-check" style="font-size:2rem"></i>
                            <div class="mt-2" style="font-size:.82rem">No open tasks assigned to you.</div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    @if($paymentSummary)
        <div class="row g-3 mt-1">
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0 c-green">৳{{ number_format($paymentSummary['todayAmount'], 0) }}</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Collected
                        Today</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0" style="color:var(--primary)">৳{{ number_format($paymentSummary['thisMonthAmount'], 0) }}</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Collected
                        This Month</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0 c-yellow">{{ $paymentSummary['pendingCount'] }}</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Unpaid
                        Invoices</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center py-3">
                    <div class="fw-bold fs-4 mb-0 c-red">৳{{ number_format($paymentSummary['pendingAmount'], 0) }}</div>
                    <div style="font-size:.69rem;color:var(--text3);text-transform:uppercase;letter-spacing:.04em">Unpaid
                        Amount</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-12">
                <div class="card section-card">
                    <div class="card-header py-3">
                        <h6 class="fw-bold mb-0">Recent Payments</h6>
                    </div>
                    <div class="card-body p-0">
                        @forelse($recentPayments as $p)
                            <div class="d-flex align-items-center gap-3 p-3" style="border-bottom:1px solid var(--border)">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small" style="color:var(--text)">{{ $p->client->client_name ?? '—' }}</div>
                                    <div style="font-size:.72rem;color:var(--text3)">{{ $p->payment_date?->format('d M Y') ?? '—' }} · {{ $p->payment_method }}</div>
                                </div>
                                <div class="fw-semibold small" style="color:var(--text)">৳{{ number_format($p->amount, 0) }}</div>
                                @php $cls = ['Paid' => 'spill-completed', 'Partial' => 'spill-warning', 'Unpaid' => 'spill-hold'][$p->status] ?? 'spill-hold'; @endphp
                                <span class="spill {{ $cls }}">{{ $p->status }}</span>
                            </div>
                        @empty
                            <div class="text-center py-5" style="color:var(--text3)">
                                <i class="bi bi-cash-coin" style="font-size:2rem"></i>
                                <div class="mt-2" style="font-size:.82rem">No payments recorded yet.</div>
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endif
    @if($stageUser)
        {{-- Submit-stage modal --}}
        <div class="modal fade" id="stageSubmitModal" tabindex="-1">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2 px-3">
                        <h6 class="modal-title fw-bold">Submit Stage</h6>
                        <button class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body px-3 py-3">
                        <input type="hidden" id="ssClient">
                        <input type="hidden" id="ssStage">
                        <div class="small mb-2" id="ssInfo" style="color:var(--text2)"></div>
                        <label class="form-label fw-semibold small">Remarks <span style="color:var(--text3)">(optional)</span></label>
                        <textarea id="ssRemarks" class="form-control form-control-sm" rows="2" placeholder="Any note for the next team…"></textarea>
                    </div>
                    <div class="modal-footer py-2 px-3">
                        <button class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-sm btn-primary" id="ssSubmit"><i class="bi bi-send me-1"></i>Submit &amp; advance</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@if($stageUser)
    @push('scripts')
        <script>
            $(document).on('click', '.stage-submit', function () {
                $('#ssClient').val($(this).data('client'));
                $('#ssStage').val($(this).data('stage'));
                $('#ssInfo').html('Submit <strong>' + $('<i>').text($(this).data('stage-name')).html() + '</strong> for <strong>' + $('<i>').text($(this).data('client-name')).html() + '</strong>? This moves it to the next team.');
                $('#ssRemarks').val('');
                new bootstrap.Modal('#stageSubmitModal').show();
            });
            $('#ssSubmit').on('click', function () {
                var client = $('#ssClient').val();
                $.post('/clients/' + client + '/stages/submit', { stage_id: $('#ssStage').val(), remarks: $('#ssRemarks').val() })
                    .done(function () {
                        bootstrap.Modal.getInstance('#stageSubmitModal').hide();
                        Swal.fire({ icon: 'success', title: 'Stage submitted', text: 'It has moved to the next team.', timer: 1400, showConfirmButton: false }).then(function () { location.reload(); });
                    })
                    .fail(function (r) { Swal.fire('Error', r.responseJSON?.message || 'Could not submit stage.', 'error'); });
            });
        </script>
    @endpush
@endif