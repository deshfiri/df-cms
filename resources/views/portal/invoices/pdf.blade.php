<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #0f172a; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        .totals { margin-top: 20px; width: 300px; margin-left: auto; }
        .totals td { border: none; padding: 4px 8px; }
        .totals .label { color: #64748b; }
        .totals .final { font-weight: bold; font-size: 14px; border-top: 1px solid #0f172a; }
    </style>
</head>
<body>
    <h1>Invoice {{ $invoice->invoice_number }}</h1>
    <div class="muted">Issued {{ $invoice->issued_date->format('d M Y') }} @if($invoice->due_date) &middot; Due {{ $invoice->due_date->format('d M Y') }} @endif</div>

    <p><strong>Client:</strong> {{ $invoice->client->client_name }} ({{ $invoice->client->dfid_number }})</p>
    @if($invoice->title)<p><strong>{{ $invoice->title }}</strong></p>@endif
    @if($invoice->description)<p>{{ $invoice->description }}</p>@endif

    <table class="totals">
        <tr><td class="label">Total Payable</td><td>৳{{ number_format($invoice->total_payable, 2) }}</td></tr>
        <tr><td class="label">Paid</td><td>৳{{ number_format($invoice->paid_amount, 2) }}</td></tr>
        <tr class="final"><td>Due</td><td>৳{{ number_format($invoice->due_amount, 2) }}</td></tr>
    </table>

    <p class="muted" style="margin-top:40px">Status: {{ $invoice->status }}</p>
</body>
</html>
