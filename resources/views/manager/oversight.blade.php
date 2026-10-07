@extends('layouts.app')
@section('title', 'Manager Oversight')

@push('styles')
<style>
    .ov-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
    .ov-stat { font-size: 1.4rem; font-weight: 700; color: var(--text); }
    .ov-label { font-size: .72rem; color: var(--text3); text-transform: uppercase; letter-spacing: .04em; }
</style>
@endpush

@section('content')
<div class="mb-3">
    <h4 class="page-title mb-0"><i class="bi bi-clipboard-data me-2"></i>Manager Oversight</h4>
    <div style="font-size:.7rem;color:var(--text3);margin-top:2px">Per-brand advertising budget, checklist holds, and current department workload</div>
</div>

{{-- Department Workload --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="ov-card p-3">
            <div class="ov-label">Content Dept</div>
            <div class="ov-stat">{{ $workload['content']['owed'] }}</div>
            <div class="small" style="color:var(--text3)">items owed</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ov-card p-3">
            <div class="ov-label">Designer Dept</div>
            <div class="ov-stat">{{ $workload['designer']['owed'] }}</div>
            <div class="small" style="color:var(--text3)">items owed</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ov-card p-3">
            <div class="ov-label">SMM</div>
            <div class="ov-stat">{{ $workload['smm']['available'] }} / {{ $workload['smm']['collected'] }}</div>
            <div class="small" style="color:var(--text3)">available / collected</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="ov-card p-3">
            <div class="ov-label">Marketing</div>
            <div class="ov-stat">{{ $workload['marketing']['unreviewed_publishes'] }}</div>
            <div class="small" style="color:var(--text3)">unreviewed publishes · {{ $workload['marketing']['pending_corrections'] }} pending corrections</div>
        </div>
    </div>
</div>

{{-- Historical activity for the selected period. Every number comes from the same definitions the Marketing and panel screens use, so they always agree. --}}
@php
    $authorColumns = [
        ['submitted', 'Submitted', 'Every submission version created in the period.'],
        ['first_submitted', 'First submissions', 'Items whose first version was submitted in the period.'],
        ['resubmitted', 'Resubmitted', 'Later versions submitted in the period, after a revision.'],
        ['revisions_received', 'Revision requests', 'Revision requests raised in the period, from any stage.'],
        ['completed', 'Completed', 'Marketing final reviews in the period of the current version.'],
    ];
    $pipelineColumns = [
        ['received', 'Received by Marketing', 'Submission versions Marketing received, by submission time.'],
        ['handed_over', 'Handed to SMM', 'Exact versions Marketing approved and handed to SMM, by approval time.'],
        ['collected', 'Collected by SMM', 'Collections by SMM in the period.'],
        ['published', 'Published / returned', 'Publications in the period, returned to Marketing for final check.'],
        ['completed', 'Completed', 'Marketing final reviews of the current version.'],
        ['revision_requested', 'Revisions requested', 'Marketing: pre-publish revisions. SMM: revisions of items SMM had collected.'],
    ];
    $brandColumns = [
        ['received', 'Received', 'Submission versions received by Marketing in the period.'],
        ['handed_over', 'Handed to SMM', 'Exact versions handed to SMM in the period.'],
        ['returned_for_final_check', 'Returned', 'Publications returned for final check in the period.'],
        ['completed', 'Completed', 'Marketing final reviews of the current version.'],
        ['revision_requested', 'Revisions', 'Marketing pre-publish revisions in the period.'],
    ];
    $mkt = $activity['departments']['marketing'];
    $smm = $activity['departments']['smm'];
    $brandRows = $activityBrands->map(fn ($brand) => [
        'label' => $brand->name,
        'values' => $activity['brands']['rows'][$brand->id] ?? [],
    ])->all();
@endphp
<div class="card section-card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <div class="fw-semibold" style="font-size:.9rem">Activity / performance <span class="small fw-normal" style="color:var(--text3)">· selected period</span></div>
                <div class="small" style="color:var(--text3)">Historical activity only. Current workload above is never period-filtered.</div>
            </div>
            @include('partials.activity-period', ['period' => $period])
        </div>

        <div class="small fw-semibold mb-1">Departments</div>
        @include('partials.activity-table', [
            'rowHeading' => 'Content & design',
            'columns' => $authorColumns,
            'rows' => [
                ['label' => 'Raw content', 'values' => $activity['departments']['content_raw']],
                ['label' => 'Advertising content', 'values' => $activity['departments']['content_advertising']],
                ['label' => 'Posters (Design)', 'values' => $activity['departments']['design']],
            ],
        ])

        <div class="small fw-semibold mt-3 mb-1">Pipeline: Marketing → SMM</div>
        @include('partials.activity-table', [
            'rowHeading' => 'Stage',
            'columns' => $pipelineColumns,
            'rows' => [
                ['label' => 'Marketing', 'values' => [
                    'received' => $mkt['received'],
                    'handed_over' => $mkt['handed_over'],
                    'collected' => '—',
                    'published' => $mkt['returned_for_final_check'],
                    'completed' => $mkt['completed'],
                    'revision_requested' => $mkt['revision_requested'],
                ]],
                ['label' => 'SMM', 'values' => [
                    'received' => '—',
                    'handed_over' => $smm['received'],
                    'collected' => $smm['collected'],
                    'published' => $smm['published'],
                    'completed' => '—',
                    'revision_requested' => $smm['revision_requested'],
                ]],
            ],
        ])

        <div class="small fw-semibold mt-3 mb-1">Brands (Marketing view)</div>
        @include('partials.activity-table', [
            'rowHeading' => 'Brand',
            'columns' => $brandColumns,
            'rows' => array_merge($brandRows, [
                ['label' => 'All brands', 'values' => $activity['brands']['totals'], 'strong' => true],
            ]),
        ])
    </div>
</div>

{{-- Brand-wise Budget --}}
<div class="ov-card mb-4">
    <div class="p-3" style="border-bottom:1px solid var(--border)">
        <strong><i class="bi bi-cash-coin me-1"></i>Advertising Budget by Brand</strong>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr class="small" style="color:var(--text3)">
                    <th>Brand</th>
                    <th>Client</th>
                    <th class="text-end">Budget</th>
                    <th class="text-end">Spent</th>
                    <th class="text-end">Remaining</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($budgets as $row)
                    <tr>
                        <td>{{ $row['brand']->name }}</td>
                        <td class="small" style="color:var(--text3)">{{ $row['brand']->client?->client_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($row['budget'], 2) }}</td>
                        <td class="text-end">{{ number_format($row['spent'], 2) }}</td>
                        <td class="text-end">{{ number_format($row['remaining'], 2) }}</td>
                        <td>
                            @if($row['is_overspent'])
                                <span class="spill spill-cancelled">Overspent by {{ number_format($row['overspent_amount'], 2) }}</span>
                            @else
                                <span class="spill spill-completed">Within budget</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">No brands with advertising activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Checklist Status Overview --}}
<div class="ov-card">
    <div class="p-3" style="border-bottom:1px solid var(--border)">
        <strong><i class="bi bi-list-check me-1"></i>Checklist Status</strong>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr class="small" style="color:var(--text3)">
                    <th>Brand</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Reason</th>
                    <th>Raw Content</th>
                    <th>Advertising Content</th>
                    <th>Poster</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($checklists as $checklist)
                    @php($counts = $categoryCounts->get($checklist->brand_id, collect()))
                    <tr data-id="{{ $checklist->id }}">
                        <td>{{ $checklist->brand?->name ?? '—' }}</td>
                        <td class="small" style="color:var(--text3)">{{ $checklist->brand?->client?->client_name ?? '—' }}</td>
                        <td>
                            @if($checklist->isOnHold())
                                <span class="spill spill-hold">On hold</span>
                            @else
                                <span class="spill spill-completed">Active</span>
                            @endif
                        </td>
                        <td class="small">{{ $checklist->on_hold_reason ?? '—' }}</td>
                        <td class="small">{{ $counts->get('raw_content', 0) }}</td>
                        <td class="small">{{ $counts->get('advertising_content', 0) }}</td>
                        <td class="small">{{ $counts->get('poster', 0) }}</td>
                        <td class="text-end">
                            @if($checklist->brand)
                                <a href="{{ route('marketing.checklist', $checklist->brand) }}" class="btn btn-sm btn-outline-secondary">View Content Checklist</a>
                            @endif
                            @if($checklist->isOnHold())
                                <button class="btn btn-sm btn-outline-primary ov-clear-hold" data-id="{{ $checklist->id }}">Clear hold</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center py-4" style="color:var(--text3)">No checklists yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Unreviewed Published Content — read-only, across every brand; the actual
     review/revision actions reuse the existing Marketing routes directly. --}}
<div class="ov-card mt-4">
    <div class="p-3" style="border-bottom:1px solid var(--border)">
        <strong><i class="bi bi-eye me-1"></i>Unreviewed Published Content</strong>
    </div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr class="small" style="color:var(--text3)">
                    <th>Brand</th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Published</th>
                    <th>Published by</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($unreviewed as $published)
                    <tr>
                        <td>{{ $published->item?->brand?->name ?? '—' }}</td>
                        <td>{{ $published->item?->title ?? '—' }}</td>
                        <td class="small" style="color:var(--text3)">{{ $published->item?->category ?? '—' }}</td>
                        <td class="small">{{ $published->published_at?->format('d M Y, h:i A') }}</td>
                        <td class="small" style="color:var(--text3)">{{ $published->publishedBy?->name ?? '—' }}</td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-danger ov-revision-btn"
                                data-brand="{{ $published->item?->brand_id }}"
                                data-item="{{ $published->content_item_id }}"
                                data-title="{{ $published->item?->title }}">Request revision</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">Nothing waiting on review.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
function ovClearHold(id, reason) {
    return $.post('{{ url('manager/checklists') }}/' + id + '/clear-hold', reason ? { reason: reason } : {});
}

$(document).on('click', '.ov-clear-hold', function () {
    const id = $(this).data('id');

    Swal.fire({ title: 'Clear this hold?', text: 'If the underlying issue is resolved, this clears automatically.', icon: 'question', showCancelButton: true, confirmButtonText: 'Clear' })
    .then(function (r) {
        if (!r.isConfirmed) return;

        ovClearHold(id, null)
        .done(function (res) {
            Swal.fire({ icon: 'success', title: res.method === 'resolved' ? 'Resolved' : 'Cleared', timer: 1400, showConfirmButton: false })
                .then(() => location.reload());
        })
        .fail(function (x) {
            if (x.status === 422 && x.responseJSON && x.responseJSON.errors && x.responseJSON.errors.reason) {
                Swal.fire({
                    title: 'Not actually resolved yet',
                    text: x.responseJSON.errors.reason[0],
                    input: 'text',
                    inputPlaceholder: 'Reason for overriding anyway',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Override & clear',
                    confirmButtonColor: '#dc3545',
                    inputValidator: (value) => !value && 'A reason is required to override.',
                }).then(function (r2) {
                    if (!r2.isConfirmed) return;
                    ovClearHold(id, r2.value)
                    .done(function () {
                        Swal.fire({ icon: 'success', title: 'Manually cleared', timer: 1400, showConfirmButton: false })
                            .then(() => location.reload());
                    })
                    .fail(function (x2) {
                        const errors = x2.responseJSON && x2.responseJSON.errors;
                        Swal.fire('Could not clear', errors ? Object.values(errors).flat().join(' ') : 'Please try again.', 'error');
                    });
                });
            } else {
                Swal.fire('Could not clear', (x.responseJSON && x.responseJSON.message) || 'Please try again.', 'error');
            }
        });
    });
});

// Reuses the existing marketing.content-items.request-revision endpoint —
// no new route, no change to its authorization or business rules.
$(document).on('click', '.ov-revision-btn', function () {
    const brand = $(this).data('brand');
    const item = $(this).data('item');
    const title = $(this).data('title');

    Swal.fire({
        title: 'Request revision', text: 'Send "' + title + '" back for rework.',
        input: 'textarea', inputPlaceholder: 'What needs to change? (required)',
        icon: 'question', showCancelButton: true, confirmButtonText: 'Send back', confirmButtonColor: '#dc3545',
        inputValidator: (value) => (!value || value.trim().length < 3) && 'A reason is required.',
    }).then(function (r) {
        if (!r.isConfirmed) return;

        $.post('/marketing/brands/' + brand + '/content-items/' + item + '/request-revision', { note: r.value })
            .done(function () {
                Swal.fire({ icon: 'success', title: 'Sent back for revision', timer: 1400, showConfirmButton: false })
                    .then(() => location.reload());
            })
            .fail(function (x) {
                const errors = x.responseJSON && x.responseJSON.errors;
                Swal.fire('Could not send back', errors ? Object.values(errors).flat().join(' ') : (x.responseJSON?.message || 'Please try again.'), 'error');
            });
    });
});
</script>
@endpush
