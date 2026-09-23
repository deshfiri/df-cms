@extends('layouts.app')
@section('title', ($departments->implode(' / ') ?: 'My') . ' Dashboard')

@push('styles')
<style>
    .mw-tabs { display: flex; gap: .3rem; flex-wrap: wrap; }
    .mw-tab {
        border: 1px solid var(--border); background: var(--surface2); color: var(--text2);
        font-size: .74rem; font-weight: 600; border-radius: 999px; padding: .2rem .7rem; cursor: pointer;
    }
    .mw-tab:hover { color: var(--text); }
    .mw-tab.active { background: rgba(var(--primary-rgb), .12); border-color: var(--primary); color: var(--primary); }
    .mw-tab .mw-n { font-variant-numeric: tabular-nums; opacity: .8; margin-left: .2rem; }

    .mw-row { display: flex; align-items: center; gap: .75rem; padding: .7rem 1rem; border-bottom: 1px solid var(--border); }
    .mw-row:last-child { border-bottom: 0; }
    .mw-title { font-weight: 600; font-size: .83rem; color: var(--text); text-decoration: none; }
    a.mw-title:hover { color: var(--primary); }
    .mw-meta { font-size: .7rem; color: var(--text3); display: flex; flex-wrap: wrap; gap: .1rem .7rem; margin-top: 1px; }
    .mw-empty { text-align: center; padding: 2.2rem 1rem; color: var(--text3); font-size: .82rem; }
    .mw-empty i { font-size: 1.8rem; display: block; margin-bottom: .4rem; }
    .mw-more { display: block; text-align: center; font-size: .74rem; padding: .55rem; border-top: 1px solid var(--border); text-decoration: none; }
</style>
@endpush

@section('content')
    @php
        $stageUser = auth()->user()->can('submit-stage') && !auth()->user()->hasRole(['Super Admin', 'Manager']);
        $prioCls   = ['Urgent' => 'spill-cancelled', 'High' => 'spill-warning'];
        // Only link to the Tasks page for people it will actually open for.
        $canTasks  = auth()->user()->canAny(['view tasks', 'manage tasks']);
    @endphp

    <div class="mb-3">
        <h4 class="page-title mb-0">{{ $stageUser ? 'My Work' : (($departments->implode(' / ') ?: 'My') . ' Team Dashboard') }}</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            {{ $stageUser ? 'Your workflow queue and tasks — claim work, do it, then hand it on.' : 'Showing only work assigned to you and your team' }}
        </div>
    </div>

    {{-- ── Counters: workload now, output today / this week / this month ── --}}
    @include('partials.my-work-panel')

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
    @endunless

    <div class="row g-3 mb-3">
        {{-- ── Workflow queue ── --}}
        <div class="col-lg-7">
            <div class="card section-card h-100" data-mw-group="flow">
                <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h6 class="fw-bold mb-0"><i class="bi bi-diagram-3 me-1"></i>My Workflow</h6>
                    <div class="mw-tabs">
                        <button type="button" class="mw-tab active" data-mw-tab="flow-mine">Mine<span class="mw-n">{{ $flowMine->count() }}</span></button>
                        <button type="button" class="mw-tab" data-mw-tab="flow-free">Available<span class="mw-n">{{ $flowAvailable->count() }}</span></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if(!$flowParticipant)
                        <div class="mw-empty">
                            <i class="bi bi-diagram-3"></i>
                            You aren't on any workflow stage yet.<br>
                            <span style="font-size:.74rem">Ask a manager to add you to a stage, and its work will appear here.</span>
                        </div>
                    @else
                        <div data-mw-pane="flow-mine">
                            @forelse($flowMine as $item)
                                <div class="mw-row">
                                    <div class="flex-grow-1 min-w-0">
                                        <a href="{{ route('flow-items.show', $item) }}" class="mw-title">{{ $item->title }}</a>
                                        <div class="mw-meta">
                                            @if($item->client)<span><i class="bi bi-person-badge me-1"></i>{{ $item->client->client_name }}</span>@endif
                                            <span><i class="bi bi-diagram-3 me-1"></i>{{ $item->flow->name ?? '—' }} · {{ $item->currentStage->name ?? '—' }}</span>
                                            @if($item->due_date)<span><i class="bi bi-calendar-event me-1"></i>Due {{ $item->due_date->format('d M Y') }}</span>@endif
                                        </div>
                                    </div>
                                    @if($item->isOverdue())
                                        <span class="spill spill-cancelled">Overdue</span>
                                    @elseif(isset($prioCls[$item->priority]))
                                        <span class="spill {{ $prioCls[$item->priority] }}">{{ $item->priority }}</span>
                                    @endif
                                    <a href="{{ route('flow-items.show', $item) }}" class="btn btn-sm btn-primary py-1 px-2" style="font-size:.72rem;white-space:nowrap">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Open
                                    </a>
                                </div>
                            @empty
                                <div class="mw-empty">
                                    <i class="bi bi-inbox"></i>
                                    Nothing claimed by you.
                                    @if($flowAvailable->isNotEmpty())
                                        <br><span style="font-size:.74rem">{{ $flowAvailable->count() }} {{ Str::plural('item', $flowAvailable->count()) }} waiting — see <strong>Available</strong>.</span>
                                    @endif
                                </div>
                            @endforelse
                        </div>

                        <div data-mw-pane="flow-free" hidden>
                            @forelse($flowAvailable as $item)
                                <div class="mw-row">
                                    <div class="flex-grow-1 min-w-0">
                                        <a href="{{ route('flow-items.show', $item) }}" class="mw-title">{{ $item->title }}</a>
                                        <div class="mw-meta">
                                            @if($item->client)<span><i class="bi bi-person-badge me-1"></i>{{ $item->client->client_name }}</span>@endif
                                            <span><i class="bi bi-diagram-3 me-1"></i>{{ $item->flow->name ?? '—' }} · {{ $item->currentStage->name ?? '—' }}</span>
                                            @if($item->due_date)<span><i class="bi bi-calendar-event me-1"></i>Due {{ $item->due_date->format('d M Y') }}</span>@endif
                                        </div>
                                    </div>
                                    @if($item->isOverdue())
                                        <span class="spill spill-cancelled">Overdue</span>
                                    @elseif(isset($prioCls[$item->priority]))
                                        <span class="spill {{ $prioCls[$item->priority] }}">{{ $item->priority }}</span>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 mw-claim" data-id="{{ $item->id }}" style="font-size:.72rem;white-space:nowrap">
                                        <i class="bi bi-hand-index-thumb me-1"></i>Claim
                                    </button>
                                </div>
                            @empty
                                <div class="mw-empty"><i class="bi bi-check2-circle"></i>Nothing waiting to be claimed.</div>
                            @endforelse
                        </div>
                        <a href="{{ route('flow.queue') }}" class="mw-more">Open My Queue <i class="bi bi-arrow-right"></i></a>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Tasks ── --}}
        <div class="col-lg-5">
            <div class="card section-card h-100" data-mw-group="task">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-2"><i class="bi bi-list-check me-1"></i>My Tasks</h6>
                    <div class="mw-tabs">
                        <button type="button" class="mw-tab active" data-mw-tab="task-open">To do<span class="mw-n">{{ $openTaskCount }}</span></button>
                        <button type="button" class="mw-tab" data-mw-tab="task-submitted">Submitted<span class="mw-n">{{ $submittedTaskCount }}</span></button>
                        <button type="button" class="mw-tab" data-mw-tab="task-done">Completed<span class="mw-n">{{ $completedTaskCount }}</span></button>
                        @if($toReviewCount)
                            <button type="button" class="mw-tab" data-mw-tab="task-review">To review<span class="mw-n">{{ $toReviewCount }}</span></button>
                        @endif
                    </div>
                </div>
                <div class="card-body p-0">
                    @foreach([
                        'task-open'      => [$myTasks, 'No open tasks assigned to you.', 'bi-list-check', $openTaskCount],
                        'task-submitted' => [$submittedTasks, 'Nothing waiting on a reviewer.', 'bi-send-check', $submittedTaskCount],
                        'task-done'      => [$completedTasks, 'No completed tasks yet.', 'bi-trophy', $completedTaskCount],
                        'task-review'    => [$toReviewTasks, 'Nothing handed in for you to review.', 'bi-clipboard-check', $toReviewCount],
                    ] as $pane => [$list, $emptyText, $emptyIcon, $total])
                        @continue($pane === 'task-review' && !$toReviewCount)
                        <div data-mw-pane="{{ $pane }}" @if($pane !== 'task-open') hidden @endif>
                            @forelse($list as $task)
                                <div class="mw-row">
                                    <div class="flex-grow-1 min-w-0">
                                        @if($canTasks)
                                            <a href="{{ route('tasks.show', $task) }}" class="mw-title">{{ $task->title }}</a>
                                        @else
                                            <span class="mw-title">{{ $task->title }}</span>
                                        @endif
                                        <div class="mw-meta">
                                            @if($task->clients->isNotEmpty())<span><i class="bi bi-person-badge me-1"></i>{{ $task->clients->pluck('client_name')->join(', ') }}</span>@endif
                                            @if($pane === 'task-review' && $task->assignees->isNotEmpty())<span><i class="bi bi-person me-1"></i>{{ $task->assignees->pluck('name')->join(', ') }}</span>@endif
                                            @if($pane === 'task-done')
                                                <span><i class="bi bi-check2 me-1"></i>{{ ($task->completion_date ?? $task->updated_at)?->format('d M Y') }}</span>
                                            @elseif($pane === 'task-submitted' || $pane === 'task-review')
                                                <span><i class="bi bi-send me-1"></i>{{ $task->submitted_at?->diffForHumans() ?? '—' }}</span>
                                            @else
                                                <span><i class="bi bi-calendar-event me-1"></i>Due {{ $task->due_date?->format('d M Y') ?? '—' }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @php
                                        $spill = match (true) {
                                            $task->is_overdue                => ['spill-cancelled', 'Overdue'],
                                            $task->status === 'Completed'    => ['spill-completed', 'Completed'],
                                            $task->status === 'Submitted'    => ['spill-warning', 'Submitted'],
                                            $task->status === 'In Progress'  => ['spill-running', 'In Progress'],
                                            default                          => ['spill-hold', $task->status],
                                        };
                                    @endphp
                                    <span class="spill {{ $spill[0] }}">{{ $spill[1] }}</span>
                                </div>
                            @empty
                                <div class="mw-empty"><i class="bi {{ $emptyIcon }}"></i>{{ $emptyText }}</div>
                            @endforelse
                            @if($total > $list->count())
                                @if($canTasks)
                                    <a href="{{ route('tasks.index') }}" class="mw-more">+{{ $total - $list->count() }} more on the Tasks page <i class="bi bi-arrow-right"></i></a>
                                @else
                                    <div class="mw-more" style="color:var(--text3)">+{{ $total - $list->count() }} more</div>
                                @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Legacy departmental pipeline — only while it still holds work. --}}
    @if($pending->isNotEmpty())
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Clients Waiting on {{ $departments->implode(' / ') ?: 'Your Team' }}</h6>
                </div>
                <div class="card-body p-0">
                    @foreach($pending as $row)
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
                                    <div style="font-size:.68rem;color:var(--c-red);margin-top:2px"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ $row->rejection_reason }}</div>
                                @endif
                            </div>
                            @if($departments->count() > 1)
                                <span style="font-size:.68rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">{{ $row->stage->department }}</span>
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
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @endif

    @unless($stageUser)
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

    @if($stageUser && $pending->isNotEmpty())
        {{-- Submit-stage modal (legacy pipeline) --}}
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

@push('scripts')
    <script>
        // Tabs within a card: show one pane, hide its siblings.
        $(document).on('click', '[data-mw-tab]', function () {
            const card = $(this).closest('[data-mw-group]');
            const pane = $(this).data('mw-tab');
            card.find('[data-mw-tab]').removeClass('active');
            $(this).addClass('active');
            card.find('[data-mw-pane]').prop('hidden', true);
            card.find('[data-mw-pane="' + pane + '"]').prop('hidden', false);
        });

        // Claim an item waiting at one of my stages, then show it under "Mine".
        $(document).on('click', '.mw-claim', function () {
            const btn = $(this).prop('disabled', true);
            $.post('/flow-items/' + btn.data('id') + '/claim')
                .done(function () {
                    Swal.fire({ icon: 'success', title: 'Claimed', text: "It's yours now.", timer: 1100, showConfirmButton: false })
                        .then(function () { location.reload(); });
                })
                .fail(function (r) {
                    btn.prop('disabled', false);
                    Swal.fire('Could not claim', r.responseJSON?.message || 'Someone may have claimed it first.', 'error')
                        .then(function () { location.reload(); });
                });
        });

        @if($stageUser && $pending->isNotEmpty())
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
        @endif
    </script>
@endpush
