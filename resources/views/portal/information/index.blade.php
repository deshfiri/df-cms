@extends('layouts.portal')
@section('title', 'My Information')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">My Information</h5>
    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#correctionModal"><i class="bi bi-pencil-square me-1"></i>Request Correction</button>
</div>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-4 mb-4">
    <div class="row g-3">
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Client Name</div><div style="font-size:.85rem">{{ $client->client_name }}</div></div>
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Brand Name</div><div style="font-size:.85rem">{{ $client->brand_name ?? '—' }}</div></div>
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">DFID</div><div style="font-size:.85rem">{{ $client->dfid_number }}</div></div>
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Contact Email</div><div style="font-size:.85rem">{{ $client->contact_email ?? '—' }}</div></div>
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Website</div><div style="font-size:.85rem">{{ $client->website ?? '—' }}</div></div>
        <div class="col-md-4"><div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Category</div><div style="font-size:.85rem">{{ $client->category?->name ?? '—' }}</div></div>
    </div>
</div>

<h6 class="mb-2">Correction Requests</h6>
<div class="card p-0">
    <table class="table mb-0">
        <thead><tr><th>Category</th><th>Field</th><th>Requested Value</th><th>Status</th><th>Submitted</th></tr></thead>
        <tbody>
        @forelse($correctionRequests as $cr)
            @php
                $badgeClass = match($cr->status) {
                    'Approved' => 'spill-green',
                    'Rejected' => 'spill-red',
                    'Need More Information' => 'spill-yellow',
                    default => 'spill-blue',
                };
            @endphp
            <tr>
                <td>{{ $cr->category }}</td>
                <td>{{ $cr->field_label }}</td>
                <td>{{ Str::limit($cr->requested_value, 50) }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $cr->status }}</span></td>
                <td style="font-size:.78rem;color:var(--text3)">{{ $cr->created_at->format('d M Y') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center py-4" style="color:var(--text3)">No correction requests submitted yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="modal fade" id="correctionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('portal.correction-requests.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title">Request Correction</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small">Category</label>
                        <select name="category" class="form-select" required>
                            @foreach(['Personal', 'Company', 'Brand', 'Contact', 'Billing', 'Delivery', 'Business', 'Product'] as $cat)
                                <option value="{{ $cat }}">{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Field</label>
                        <input type="text" name="field_label" class="form-control" placeholder="e.g. Business Address" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Current Value</label>
                        <input type="text" name="current_value" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Requested Value</label>
                        <input type="text" name="requested_value" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reason</label>
                        <textarea name="reason" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Supporting Document (optional)</label>
                        <input type="file" name="file" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
