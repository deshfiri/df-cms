<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => $client->invoices()->get()]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'title'         => ['nullable', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'total_payable' => ['required', 'numeric', 'min:0'],
            'due_date'      => ['nullable', 'date'],
            'issued_date'   => ['nullable', 'date'],
            'remarks'       => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice = $this->service->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $invoice]);
    }

    public function update(Request $request, Client $client, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($invoice->client_id !== $client->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(Invoice::$statuses)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice->update($data);

        return response()->json(['success' => true, 'data' => $invoice]);
    }

    public function destroy(Client $client, Invoice $invoice): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($invoice->client_id !== $client->id, 404);

        $invoice->delete();

        return response()->json(['success' => true]);
    }
}
