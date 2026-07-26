<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\Invoice;
use App\Policies\Portal\InvoicePolicy;
use App\Services\PaymentProofService;
use Illuminate\Http\Request;

class PortalPaymentProofController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly InvoicePolicy $policy,
        private readonly PaymentProofService $service,
    ) {}

    public function store(Request $request, Invoice $invoice)
    {
        abort_unless($this->policy->view($this->portalUser(), $invoice), 404);

        $data = $request->validate([
            'amount_claimed'        => ['nullable', 'numeric', 'min:0'],
            'payment_method'        => ['nullable', 'string', 'max:100'],
            'transaction_reference' => ['nullable', 'string', 'max:100'],
            'payment_date'          => ['nullable', 'date'],
            'file'                  => ['required', 'file', 'max:20480'],
        ]);

        $this->service->submit(
            $invoice->client,
            $this->portalUser(),
            $invoice->id,
            $data['amount_claimed'] ?? null,
            $data['payment_method'] ?? null,
            $data['transaction_reference'] ?? null,
            $data['payment_date'] ?? null,
            $request->file('file'),
        );

        return redirect()->route('portal.invoices.show', $invoice)->with('success', 'Payment proof submitted for verification.');
    }
}
