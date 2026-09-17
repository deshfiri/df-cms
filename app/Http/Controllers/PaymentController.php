<?php

namespace App\Http\Controllers;

use App\Http\Requests\Payment\StorePaymentRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentCategory;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $service,
        private readonly InvoiceService $invoices,
    ) {}

    /** Everything the client's Payments tab draws: history, charges and the per-category picture. */
    public function index(Client $client): JsonResponse
    {
        $this->authorizeView($client);

        $payments = $client->payments()
            ->with(['createdBy:id,name', 'category:id,name', 'invoice:id,invoice_number,title'])
            ->get();

        // A payment with a correction waiting can't take another until it is reviewed.
        $waiting = \App\Models\PendingChange::where('model_type', Payment::class)
            ->pending()
            ->whereIn('model_id', $payments->pluck('id'))
            ->pluck('model_id')
            ->flip();
        $payments->each(fn (Payment $p) => $p->setAttribute('change_waiting', $waiting->has($p->id)));

        // What has gone back, what is spoken for, and what is still refundable.
        $refunds = \App\Models\Refund::whereIn('payment_id', $payments->pluck('id'))
            ->whereIn('status', \App\Models\Refund::COMMITTED_STATUSES)
            ->get(['id', 'payment_id', 'refund_number', 'amount', 'status'])
            ->groupBy('payment_id');
        $payments->each(function (Payment $p) use ($refunds) {
            $mine      = $refunds->get($p->id, collect());
            $committed = (float) $mine->sum('amount');
            $open      = $mine->first(fn ($r) => $r->isOpen());
            $received  = in_array($p->status, ['Paid', 'Partial'], true) ? (float) $p->amount : 0.0;

            $p->setAttribute('refunded_amount', number_format((float) $mine->where('status', \App\Models\Refund::STATUS_COMPLETED)->sum('amount'), 2, '.', ''));
            $p->setAttribute('refundable_amount', number_format(max(0, $received - $committed), 2, '.', ''));
            $p->setAttribute('open_refund', $open ? ['id' => $open->id, 'number' => $open->refund_number, 'status_label' => $open->status_label, 'amount' => $open->amount] : null);
        });

        $charges = $client->invoices()
            ->with('category:id,name')
            ->withPaidTotal()
            ->get()
            ->map(fn (Invoice $i) => $this->invoices->present($i));

        return response()->json([
            'payments'   => $payments,
            'summary'    => $this->service->summaryForClient($client),
            'charges'    => $charges,
            'categories' => PaymentCategory::active()->ordered()->get(['id', 'name']),
        ]);
    }

    /** Standalone "all payments across all clients" page, reachable from the sidebar. */
    public function all(Request $request)
    {
        abort_unless($request->user()->can('view payments'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients    = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);
        $categories = PaymentCategory::ordered()->get(['id', 'name', 'is_active']);

        $open = Invoice::query()
            ->whereNotIn('status', Invoice::$terminalStatuses)
            ->whereHas('client')
            ->withPaidTotal()
            ->get(['id', 'payment_category_id', 'total_payable', 'status']);

        $totals = [
            'paid'         => Payment::where('status', 'Paid')->sum('amount'),
            'partial'      => Payment::where('status', 'Partial')->sum('amount'),
            'unpaid_count' => Payment::where('status', 'Unpaid')->count(),
            'outstanding'  => round($open->sum(fn (Invoice $i) => $i->due_amount), 2),
            'open_charges' => $open->filter(fn (Invoice $i) => $i->due_amount > 0)->count(),
        ];

        $byCategory = $this->categoryTotals($categories, $open);

        return view('payments.index', compact('clients', 'categories', 'totals', 'byCategory'));
    }

    /**
     * Received and outstanding per category across every client — the
     * "where is our money" view for the Payments page.
     */
    private function categoryTotals($categories, $open): array
    {
        $received = Payment::query()
            ->where('status', 'Paid')
            ->selectRaw('payment_category_id, SUM(amount) as total')
            ->groupBy('payment_category_id')
            ->pluck('total', 'payment_category_id');

        $billed = Invoice::query()
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereHas('client')
            ->selectRaw('payment_category_id, SUM(total_payable) as total')
            ->groupBy('payment_category_id')
            ->pluck('total', 'payment_category_id');

        $rows = [];
        foreach ($categories as $category) {
            $due = $open->where('payment_category_id', $category->id)->sum(fn (Invoice $i) => $i->due_amount);
            $row = [
                'id'       => $category->id,
                'name'     => $category->name,
                'billed'   => round((float) ($billed[$category->id] ?? 0), 2),
                'received' => round((float) ($received[$category->id] ?? 0), 2),
                'due'      => round((float) $due, 2),
            ];

            if ($row['billed'] > 0 || $row['received'] > 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = Payment::query()->with([
            'client:id,client_name,dfid_number',
            'createdBy:id,name',
            'category:id,name',
            'invoice:id,invoice_number,title,total_payable,status',
        ]);

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('category_id')) {
            $request->category_id === 'none'
                ? $query->whereNull('payment_category_id')
                : $query->where('payment_category_id', $request->category_id);
        }

        // Counted before the status pill narrows it, so every pill shows what it
        // would give under the client and category currently chosen.
        // select() replaces any columns the list query carries, so the GROUP BY
        // stays valid under MySQL's only_full_group_by.
        $byStatus = (clone $query)->reorder()
            ->select('status')->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $counts = ['total' => (int) $byStatus->sum(), 'status' => $byStatus->map(fn ($n) => (int) $n)];

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $canManage = $request->user()->can('manage payments');

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (Payment $p) => e($p->client->client_name ?? '—')
                . ($p->client?->dfid_number ? ' <span class="text-muted small">(' . e($p->client->dfid_number) . ')</span>' : ''))
            ->addColumn('category_name', fn (Payment $p) => $p->category
                ? '<span class="pay-cat">' . e($p->category->name) . '</span>'
                : '<span style="color:var(--text3)">—</span>')
            ->addColumn('charge', fn (Payment $p) => $p->invoice
                ? '<span style="font-weight:600">' . e($p->invoice->invoice_number) . '</span>'
                    . ($p->invoice->title ? '<div style="font-size:.72rem;color:var(--text3)">' . e($p->invoice->title) . '</div>' : '')
                : '<span style="color:var(--text3)">—</span>')
            ->addColumn('status_badge', fn (Payment $p) => $this->statusBadge($p->status))
            ->addColumn('amount_fmt', fn (Payment $p) => $p->amount !== null ? '৳' . number_format($p->amount, 2) : '—')
            ->addColumn('date_fmt', fn (Payment $p) => $p->payment_date?->format('d M Y') ?? '—')
            ->addColumn('created_by_name', fn (Payment $p) => e($p->createdBy->name ?? '—'))
            ->addColumn('actions', function (Payment $p) use ($canManage) {
                $html = '<a href="' . route('clients.show', $p->client_id) . '#tab-payments" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View Client"><i class="bi bi-eye"></i></a> '
                    . '<button class="btn btn-sm px-2 py-1 payment-history" data-id="' . $p->id . '" data-client="' . $p->client_id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Correction history"><i class="bi bi-clock-history"></i></button> ';
                if ($canManage) {
                    $html .= '<button class="btn btn-sm px-2 py-1 payment-delete" data-id="' . $p->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
                }

                return $html;
            })
            ->rawColumns(['client', 'category_name', 'charge', 'status_badge', 'actions'])
            ->orderColumn('date_fmt', 'payment_date $1')
            ->with(['counts' => $counts])
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

        $data = $request->validate(
            ['client_id' => ['required', 'exists:clients,id']] + StorePaymentRequest::baseRules(),
            StorePaymentRequest::baseMessages(),
        );

        $client = Client::findOrFail($data['client_id']);
        unset($data['client_id']);

        $payment = $this->service->create($client, $data);

        return response()->json(['success' => true, 'payment' => $payment->load('client:id,client_name,dfid_number')]);
    }

    public function destroyAny(Request $request, Payment $payment): JsonResponse
    {
        abort_unless(Auth::user()->can('manage payments'), 403);

        return $this->changeResponse(
            $this->service->requestDelete($payment, $this->reason($request), $request->user()),
            'Payment deleted.',
        );
    }

    public function store(StorePaymentRequest $request, Client $client): JsonResponse
    {
        $this->authorizeMoney($client);

        $payment = $this->service->create($client, $request->validated());

        return response()->json([
            'success' => true,
            'payment' => $payment->load('createdBy:id,name'),
        ]);
    }

    /**
     * Correct a payment. Applied at once for an approver; otherwise it waits in
     * the approval queue (202). Either way it is recorded — see history().
     */
    public function update(StorePaymentRequest $request, Client $client, Payment $payment): JsonResponse
    {
        $this->authorizeMoney($client);
        abort_if((int) $payment->client_id !== $client->id, 404);

        return $this->changeResponse(
            $this->service->requestUpdate($payment, $request->validated(), $this->reason($request), $request->user()),
            'Payment updated.',
        );
    }

    public function destroy(Request $request, Client $client, Payment $payment): JsonResponse
    {
        $this->authorizeMoney($client);
        abort_if((int) $payment->client_id !== $client->id, 404);

        return $this->changeResponse(
            $this->service->requestDelete($payment, $this->reason($request), $request->user()),
            'Payment deleted.',
        );
    }

    /** Every correction requested for this payment: what, who, when, why, and the outcome. */
    public function history(Client $client, int $payment): JsonResponse
    {
        $this->authorizeView($client);

        // The trail outlives the payment, so it is looked up by id — but only
        // under the client it belonged to, which every change row records.
        $belongs = Payment::whereKey($payment)->where('client_id', $client->id)->exists()
            || \App\Models\PendingChange::for(Payment::class, $payment)
                ->get(['old_values'])
                ->contains(fn ($c) => (int) ($c->old_values['client_id'] ?? 0) === $client->id);
        abort_unless($belongs, 404);

        return response()->json(['data' => $this->service->history($payment)]);
    }

    private function reason(Request $request): string
    {
        return $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Say why this payment is being changed — it is kept on the record.'],
        )['reason'];
    }

    /** @param array{applied:bool, change:\App\Models\PendingChange, payment?:Payment} $result */
    private function changeResponse(array $result, string $appliedMessage): JsonResponse
    {
        if ($result['applied']) {
            return response()->json([
                'success' => true,
                'applied' => true,
                'message' => $appliedMessage,
                'payment' => $result['payment'] ?? null,
                'change_id' => $result['change']->id,
            ]);
        }

        return response()->json([
            'success'   => true,
            'applied'   => false,
            'pending'   => true,
            'message'   => 'Sent for approval. A Super Admin or Manager has to approve it before it takes effect.',
            'change_id' => $result['change']->id,
        ], 202);
    }

    /**
     * Accounts staff hold "manage payments" without owning the client, and the
     * standalone Payments page already lets them record for anyone — so either
     * that or the right to edit this client is enough here.
     */
    private function authorizeMoney(Client $client): void
    {
        $user = Auth::user();

        abort_unless($user->can('manage payments') || $user->can('update', $client), 403);
    }

    private function authorizeView(Client $client): void
    {
        $user = Auth::user();

        abort_unless($user->can('view payments') || $user->can('manage payments') || $user->can('view', $client), 403);
    }
}
