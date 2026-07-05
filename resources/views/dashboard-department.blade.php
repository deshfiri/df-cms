@extends('layouts.app')
@section('title', $departments->implode(' / ') . ' Dashboard')

@section('content')
    <div class="mb-3">
        <h4 class="page-title mb-0">{{ $departments->implode(' / ') }} Team Dashboard</h4>
        <div style="font-size:.7rem;color:var(--text3);margin-top:2px">
            Showing only work assigned to your team{{ $departments->count() > 1 ? 's' : '' }}
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

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card section-card">
                <div class="card-header py-3">
                    <h6 class="fw-bold mb-0">Clients Waiting on {{ $departments->implode(' / ') }}</h6>
                </div>
                <div class="card-body p-0">
                    @forelse($pending as $row)
                        <a href="{{ route('clients.show', $row->client_id) }}"
                            class="d-flex align-items-center gap-3 p-3 text-decoration-none"
                            style="border-bottom:1px solid var(--border)">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small" style="color:var(--text)">{{ $row->client->client_name ?? '—' }}
                                </div>
                                <div style="font-size:.72rem;color:var(--text3)">{{ $row->stage->name }}</div>
                            </div>
                            @if($departments->count() > 1)
                                <span
                                    style="font-size:.68rem;background:rgba(var(--primary-rgb),.1);color:var(--primary);padding:2px 8px;border-radius:20px">{{ $row->stage->department }}</span>
                            @endif
                            @php $cls = ['Pending' => 'spill-pending', 'Submitted' => 'spill-submitted', 'Need Revision' => 'spill-need-revision'][$row->status] ?? 'spill-pending'; @endphp
                            <span class="spill {{ $cls }}">{{ $row->status }}</span>
                        </a>
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
@endsection