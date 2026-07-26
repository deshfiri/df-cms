<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\Invoice;
use App\Policies\Portal\InvoicePolicy;
use App\Services\PortalActivityLogService;
use Barryvdh\DomPDF\Facade\Pdf;

class PortalInvoiceController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly InvoicePolicy $policy,
        private readonly PortalActivityLogService $activityLog,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $invoices = Invoice::where('client_id', $client->id)->latest()->get();
        $payments = $client->payments()->with('invoice')->get();

        return view('portal.invoices.index', compact('invoices', 'payments'));
    }

    public function show(Invoice $invoice)
    {
        abort_unless($this->policy->view($this->portalUser(), $invoice), 404);

        $payments = $invoice->payments()->get();

        return view('portal.invoices.show', compact('invoice', 'payments'));
    }

    public function download(Invoice $invoice)
    {
        abort_unless($this->policy->view($this->portalUser(), $invoice), 404);

        $this->activityLog->log($this->portalUser(), 'Invoice', 'Downloaded', Invoice::class, $invoice->id);

        $pdf = Pdf::loadView('portal.invoices.pdf', compact('invoice'))->setPaper('A4');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
