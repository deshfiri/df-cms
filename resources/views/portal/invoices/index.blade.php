@extends('layouts.portal')
@section('title', 'Payments')

@section('content')
<h5 class="mb-3">Invoices</h5>

<div class="card p-0 mb-4">
    <table class="table mb-0">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Title</th>
                <th>Total</th>
                <th>Paid</th>
                <th>Due</th>
                <th>Due Date</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        @forelse($invoices as $invoice)
            @php
                $badgeClass = match($invoice->status) {
                    'Paid' => 'spill-green',
                    'Partially Paid' => 'spill-blue',
                    'Overdue' => 'spill-red',
                    'Unpaid' => 'spill-yellow',
                    default => 'spill-gray',
                };
            @endphp
            <tr>
                <td>{{ $invoice->invoice_number }}</td>
                <td>{{ $invoice->title ?? '—' }}</td>
                <td>৳{{ number_format($invoice->total_payable, 2) }}</td>
                <td>৳{{ number_format($invoice->paid_amount, 2) }}</td>
                <td>৳{{ number_format($invoice->due_amount, 2) }}</td>
                <td style="font-size:.78rem;color:var(--text2)">{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $invoice->status }}</span></td>
                <td class="text-end">
                    <a href="{{ route('portal.invoices.show', $invoice) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                    <a href="{{ route('portal.invoices.download', $invoice) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center py-4" style="color:var(--text3)">No invoices yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<h5 class="mb-3">Payment History</h5>
<div class="card p-0">
    <table class="table mb-0">
        <thead>
            <tr>
                <th>Date</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Transaction Ref</th>
                <th>Invoice</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        @forelse($payments as $payment)
            @php
                $badgeClass = match($payment->status) {
                    'Paid' => 'spill-green',
                    'Partial' => 'spill-blue',
                    default => 'spill-yellow',
                };
            @endphp
            <tr>
                <td style="font-size:.8rem">{{ $payment->payment_date?->format('d M Y') ?? '—' }}</td>
                <td>৳{{ number_format($payment->amount, 2) }}</td>
                <td>{{ $payment->payment_method ?? '—' }}</td>
                <td style="font-size:.78rem;color:var(--text3)">{{ $payment->transaction_number ?? '—' }}</td>
                <td>{{ $payment->invoice?->invoice_number ?? '—' }}</td>
                <td><span class="spill {{ $badgeClass }}">{{ $payment->status }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="text-center py-4" style="color:var(--text3)">No payment history yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
