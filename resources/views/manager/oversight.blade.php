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
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($checklists as $checklist)
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
                        <td class="text-end">
                            @if($checklist->isOnHold())
                                <button class="btn btn-sm btn-outline-primary ov-clear-hold" data-id="{{ $checklist->id }}">Clear hold</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center py-4" style="color:var(--text3)">No checklists yet.</td></tr>
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
</script>
@endpush
