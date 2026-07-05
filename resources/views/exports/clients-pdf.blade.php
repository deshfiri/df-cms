<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Client Report — DFCP COMS</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #333; }
        h1 { font-size: 14pt; color: #1F3C88; margin-bottom: 4px; }
        .meta { font-size: 8pt; color: #777; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1F3C88; color: white; padding: 5px 6px; font-size: 8pt; text-align: left; }
        td { padding: 4px 6px; border-bottom: 1px solid #eee; font-size: 8pt; }
        tr:nth-child(even) td { background: #f9f9f9; }
        .badge { padding: 2px 6px; border-radius: 3px; font-size: 7pt; color: white; }
        .badge-running { background: #198754; }
        .badge-warning { background: #ffc107; color: #000; }
        .badge-completed { background: #0d6efd; }
        .badge-hold { background: #6c757d; }
        .badge-cancelled { background: #dc3545; }
    </style>
</head>
<body>
<h1>DF Commerce Client Report</h1>
<div class="meta">Generated: {{ now()->format('d M Y, H:i') }} · Total: {{ $clients->count() }} clients</div>

<table>
    <thead>
        <tr>
            <th>#</th>
            <th>DFID</th>
            <th>Client Name</th>
            <th>Brand</th>
            <th>Category</th>
            <th>Status</th>
            <th>Progress</th>
            <th>Joined</th>
        </tr>
    </thead>
    <tbody>
        @foreach($clients as $i => $client)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $client->dfid_number }}</td>
            <td>{{ $client->client_name }}</td>
            <td>{{ $client->brand_name }}</td>
            <td>{{ $client->category?->name }}</td>
            <td><span class="badge badge-{{ strtolower($client->client_status) }}">{{ $client->client_status }}</span></td>
            <td>{{ $client->progress }}%</td>
            <td>{{ $client->joining_date?->format('d M Y') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
