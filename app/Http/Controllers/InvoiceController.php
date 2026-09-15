<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Charges — what a client owes, per category ("Social Media Ads, ৳20,000").
 *
 * Stored as invoices, so the client portal, PDF download and payment-proof flow
 * all work on them unchanged. Payments are taken against them through
 * PaymentController; the status here follows the money on its own.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $service,
    ) {}

    public function index(Request $request, Client $client): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user->can('view payments') || $user->can('manage payments') || $user->can('view', $client), 403);

        $charges = $client->invoices()
            ->with('category:id,name')
            ->withPaidTotal()
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', Invoice::$terminalStatuses))
            ->when($request->filled('category_id'), fn ($q) => $q->where('payment_category_id', $request->category_id))
            ->get()
            ->map(fn (Invoice $i) => $this->service->present($i))
            // "Open" means money can still be taken, not merely "not cancelled".
            ->when($request->boolean('open'), fn ($c) => $c->filter(fn (array $i) => $i['is_open']))
            ->values();

        return response()->json(['data' => $charges]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorizeMoney($client);

        $data = $request->validate([
            'payment_category_id' => ['nullable', 'integer', Rule::exists('payment_categories', 'id')->where('is_active', true)],
            'title'               => ['nullable', 'string', 'max:200'],
            'description'         => ['nullable', 'string', 'max:2000'],
            'total_payable'       => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'due_date'            => ['nullable', 'date'],
            'issued_date'         => ['nullable', 'date'],
            'remarks'             => ['nullable', 'string', 'max:2000'],
        ], [
            'payment_category_id.exists' => 'Pick an active payment category.',
        ]);

        $invoice = $this->service->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $this->service->present($invoice->load('category'))]);
    }

    public function update(Request $request, Client $client, Invoice $invoice): JsonResponse
    {
        $this->authorizeMoney($client);
        abort_if((int) $invoice->client_id !== $client->id, 404);

        $data = $request->validate([
            // Existing, not necessarily active: a charge may keep a category that has since been archived.
            'payment_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('payment_categories', 'id')],
            'title'               => ['sometimes', 'nullable', 'string', 'max:200'],
            'description'         => ['sometimes', 'nullable', 'string', 'max:2000'],
            'total_payable'       => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:9999999999'],
            'due_date'            => ['sometimes', 'nullable', 'date'],
            'status'              => ['sometimes', 'required', Rule::in(Invoice::$statuses)],
            'remarks'             => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $invoice = $this->service->update($invoice, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $this->service->present($invoice->load('category'))]);
    }

    public function destroy(Client $client, Invoice $invoice): JsonResponse
    {
        $this->authorizeMoney($client);
        abort_if((int) $invoice->client_id !== $client->id, 404);

        // Money received stays on record; a charge with payments is cancelled, not removed.
        if ($invoice->payments()->exists()) {
            return response()->json([
                'message' => 'Payments have been recorded against ' . $invoice->invoice_number . '. Cancel it instead, or delete those payments first.',
            ], 422);
        }

        $invoice->delete();

        return response()->json(['success' => true]);
    }

    /** Same rule as recording a payment: "manage payments", or the right to edit this client. */
    private function authorizeMoney(Client $client): void
    {
        $user = Auth::user();

        abort_unless($user->can('manage payments') || $user->can('update', $client), 403);
    }
}
