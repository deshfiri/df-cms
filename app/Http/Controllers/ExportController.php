<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService $exportService,
    ) {}

    public function clients(Request $request): BinaryFileResponse
    {
        $filters = $request->only(['status', 'category_id', 'assigned_to', 'search', 'date_from', 'date_to']);
        $format  = $request->input('format', 'excel');
        $ids     = $request->has('ids') ? explode(',', $request->ids) : null;

        $path = $this->exportService->exportClients($filters, $format, $ids);

        $mime = match ($format) {
            'csv'  => 'text/csv',
            'pdf'  => 'application/pdf',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };

        $ext = ['csv' => 'csv', 'pdf' => 'pdf'][$format] ?? 'xlsx';

        return response()->download($path, 'dfcp_clients_' . now()->format('Ymd_His') . '.' . $ext, [
            'Content-Type' => $mime,
        ])->deleteFileAfterSend();
    }
}
