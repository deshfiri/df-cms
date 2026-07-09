<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

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

    /** Standalone "all payments across all clients" page, reachable from the sidebar. */
    public function all(Request $request)
    {
        abort_unless($request->user()->can('view payments'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);

        $totals = [
            'paid'         => Payment::where('status', 'Paid')->sum('amount'),
            'partial'      => Payment::where('status', 'Partial')->sum('amount'),
            'unpaid_count' => Payment::where('status', 'Unpaid')->count(),
        ];

        return view('payments.index', compact('clients', 'totals'));
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['client:id,client_name,dfid_number', 'createdBy:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        $canManage = $request->user()->can('manage payments');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (Payment $p) => e($p->client->client_name ?? '—')
                . ($p->client?->dfid_number ? ' <span class="text-muted small">(' . e($p->client->dfid_number) . ')</span>' : ''))
            ->addColumn('status_badge', fn (Payment $p) => $this->statusBadge($p->status))
            ->addColumn('amount_fmt', fn (Payment $p) => $p->amount !== null ? '৳' . number_format($p->amount, 2) : '—')
            ->addColumn('date_fmt', fn (Payment $p) => $p->payment_date?->format('d M Y') ?? '—')
            ->addColumn('created_by_name', fn (Payment $p) => e($p->createdBy->name ?? '—'))
            ->addColumn('actions', function (Payment $p) use ($canManage) {
                $html = '<a href="' . route('clients.show', $p->client_id) . '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View Client"><i class="bi bi-eye"></i></a> ';
                if ($canManage) {
                    $html .= '<button class="btn btn-sm px-2 py-1 payment-delete" data-id="' . $p->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
                }

                return $html;
            })
            ->rawColumns(['client', 'status_badge', 'actions'])
            ->orderColumn('date_fmt', 'payment_date $1')
            ->make(true);
    }

    private function statusBadge(string $status): string
    {
        $map = ['Paid' => 'spill-completed', 'Partial' => 'spill-warning', 'Unpaid' => 'spill-hold'];

        return '<span class="spill ' . ($map[$status] ?? 'spill-hold') . '">' . e($status) . '</span>';
    }

    /** Record a payment for any client, picked from the modal — used by the standalone Payments page. */
    public function storeAny(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage payments'), 403);

        $data = $request->validate([
            'client_id'          => ['required', 'exists:clients,id'],
            'amount'             => ['nullable', 'numeric', 'min:0'],
            'payment_date'       => ['nullable', 'date'],
            'payment_method'     => ['nullable', 'string', 'max:100'],
            'transaction_number' => ['nullable', 'string', 'max:100'],
            'status'             => ['required', Rule::in(Payment::$statuses)],
            'remarks'            => ['nullable', 'string'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        unset($data['client_id']);

        $payment = $this->service->create($client, $data);

        return response()->json(['success' => true, 'payment' => $payment->load('client:id,client_name,dfid_number')]);
    }

    public function destroyAny(Payment $payment): JsonResponse
    {
        abort_unless(Auth::user()->can('manage payments'), 403);

        $this->service->delete($payment);

        return response()->json(['success' => true]);
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
