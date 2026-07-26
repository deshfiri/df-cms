@extends('layouts.portal')
@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Invoice {{ $invoice->invoice_number }}</h5>
    <div class="d-flex gap-2">
        @if($invoice->due_amount > 0)
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#proofModal"><i class="bi bi-upload me-1"></i>Submit Payment Proof</button>
        @endif
        <a href="{{ route('portal.invoices.download', $invoice) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Download PDF</a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success" style="font-size:.85rem">{{ session('success') }}</div>
@endif

<div class="card p-4 mb-3">
    <div class="row g-3">
        <div class="col-md-4">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Total Payable</div>
            <div style="font-size:1.1rem;font-weight:700">৳{{ number_format($invoice->total_payable, 2) }}</div>
        </div>
        <div class="col-md-4">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Paid</div>
            <div style="font-size:1.1rem;font-weight:700;color:var(--c-green)">৳{{ number_format($invoice->paid_amount, 2) }}</div>
        </div>
        <div class="col-md-4">
            <div style="font-size:.68rem;color:var(--text3);text-transform:uppercase">Due</div>
            <div style="font-size:1.1rem;font-weight:700;color:var(--c-red)">৳{{ number_format($invoice->due_amount, 2) }}</div>
        </div>
    </div>
    @if($invoice->description)
        <div class="mt-3" style="font-size:.85rem;color:var(--text2)">{{ $invoice->description }}</div>
    @endif
</div>

<div class="card p-0">
    <table class="table mb-0">
        <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($payments as $payment)
            <tr>
                <td>{{ $payment->payment_date?->format('d M Y') ?? '—' }}</td>
                <td>৳{{ number_format($payment->amount, 2) }}</td>
                <td>{{ $payment->payment_method ?? '—' }}</td>
                <td>{{ $payment->transaction_number ?? '—' }}</td>
                <td>{{ $payment->status }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="text-center py-4" style="color:var(--text3)">No payments recorded against this invoice yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if($invoice->due_amount > 0)
<div class="modal fade" id="proofModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('portal.invoices.payment-proof.store', $invoice) }}" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h6 class="modal-title">Submit Payment Proof</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small">Amount Paid</label>
                        <input type="number" step="0.01" name="amount_claimed" class="form-control" value="{{ $invoice->due_amount }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Payment Method</label>
                        <input type="text" name="payment_method" class="form-control" placeholder="e.g. bKash, Bank Transfer">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Transaction Reference</label>
                        <input type="text" name="transaction_reference" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Payment Date</label>
                        <input type="date" name="payment_date" class="form-control" value="{{ now()->format('Y-m-d') }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Proof (screenshot/receipt) <span class="text-danger">*</span></label>
                        <input type="file" name="file" class="form-control" required>
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
@endif
@endsection
