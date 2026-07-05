<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $payments = $client->payments()->with('createdBy:id,name')->get();
        $summary  = $this->service->summaryForClient($client);

        return response()->json(['payments' => $payments, 'summary' => $summary]);
    }

    public function store(StorePaymentRequest $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $payment = $this->service->create($client, $request->validated());

        return response()->json([
            'success' => true,
            'payment' => $payment->load('createdBy:id,name'),
        ]);
    }

    public function update(StorePaymentRequest $request, Client $client, Payment $payment): JsonResponse
    {
        abort_unless(Auth::user()->can('manage payments'), 403);
        $this->authorize('update', $client);

        $updated = $this->service->update($payment, $request->validated());

        return response()->json(['success' => true, 'payment' => $updated]);
    }

    public function destroy(Client $client, Payment $payment): JsonResponse
    {
        $this->authorize('update', $client);

        $this->service->delete($payment);

        return response()->json(['success' => true]);
    }
}
