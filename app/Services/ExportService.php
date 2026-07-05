<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ExportLog;
use App\Repositories\Contracts\ClientRepositoryInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService
{
    public function __construct(
        private readonly ClientRepositoryInterface $clientRepo,
    ) {}

    public function exportClients(array $filters, string $format, ?array $ids = null): string
    {
        $clients = $ids
            ? Client::with(['category', 'stageProgress', 'productUpdates', 'payments'])
                ->whereIn('id', $ids)->get()
            : $this->clientRepo->allForExport($filters);

        $this->logExport($format, $filters, count($clients));

        return match ($format) {
            'csv'  => $this->toCsv($clients),
            'pdf'  => $this->toPdf($clients),
            default => $this->toExcel($clients),
        };
    }

    private function toExcel(Collection $clients): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Clients');

        // Header row
        $headers = [
            'Serial', 'DFID', 'Client Name', 'Brand Name', 'Category',
            'Website', 'Joining Date', 'Status', 'Progress %',
            'Product Status', 'Payment Status', 'Notes',
        ];

        foreach ($headers as $col => $header) {
            $cell = chr(65 + $col) . '1';
            $sheet->setCellValue($cell, $header);
        }

        $sheet->getStyle('A1:L1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F3C88']],
        ]);

        foreach ($clients as $i => $client) {
            $row = $i + 2;
            $sheet->fromArray([
                $i + 1,
                $client->dfid_number,
                $client->client_name,
                $client->brand_name,
                $client->category?->name,
                $client->website,
                $client->joining_date?->format('d-M-Y'),
                $client->client_status,
                $client->progress . '%',
                $client->latestProductStatus,
                $client->latestPaymentStatus,
                $client->remarks,
            ], null, 'A' . $row);
        }

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $path = storage_path('app/exports/clients_' . now()->format('Ymd_His') . '.xlsx');
        @mkdir(dirname($path), 0755, true);

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function toCsv(Collection $clients): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();

        $sheet->fromArray([[
            'Serial', 'DFID', 'Client Name', 'Brand Name', 'Category',
            'Website', 'Joining Date', 'Status', 'Progress %',
        ]], null, 'A1');

        foreach ($clients as $i => $client) {
            $sheet->fromArray([
                $i + 1, $client->dfid_number, $client->client_name,
                $client->brand_name, $client->category?->name,
                $client->website, $client->joining_date?->format('d-M-Y'),
                $client->client_status, $client->progress . '%',
            ], null, 'A' . ($i + 2));
        }

        $path = storage_path('app/exports/clients_' . now()->format('Ymd_His') . '.csv');
        @mkdir(dirname($path), 0755, true);

        (new Csv($spreadsheet))->save($path);

        return $path;
    }

    private function toPdf(Collection $clients): string
    {
        $pdf  = Pdf::loadView('exports.clients-pdf', compact('clients'))->setPaper('A4', 'landscape');
        $path = storage_path('app/exports/clients_' . now()->format('Ymd_His') . '.pdf');
        @mkdir(dirname($path), 0755, true);
        $pdf->save($path);

        return $path;
    }

    private function logExport(string $format, array $filters, int $count): void
    {
        \App\Models\ExportLog::create([
            'user_id'      => Auth::id(),
            'export_type'  => $format,
            'scope'        => 'filtered',
            'record_count' => $count,
            'filters'      => $filters,
        ]);
    }
}
