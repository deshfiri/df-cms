<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

/**
 * Refunds: the queue page, a client's refunds, and each step of one.
 *
 * Every step answers the same JSON — {success, message, refund} — and every
 * refusal is a 422 with the reason (a rule of money) or a 403 with the reason
 * (a rule of who).
 */
class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', Refund::class);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        return view('refunds.index', [
            'statuses' => Refund::LABELS,
            'clients'  => Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']),
        ]);
    }

    public function forClient(Request $request, Client $client): JsonResponse
    {
        $this->authorize('viewAny', Refund::class);

        $refunds = Refund::where('client_id', $client->id)->latest('id')->get();

        return response()->json([
            'data' => $refunds->map(fn (Refund $r) => $this->refunds->present($r, $request->user()))->all(),
        ]);
    }

    public function show(Request $request, Refund $refund): JsonResponse
    {
        $this->authorize('view', $refund);

        return response()->json(['refund' => $this->refunds->present($refund, $request->user(), withEvents: true)]);
    }

    public function store(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('request', Refund::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'method' => ['nullable', 'string', 'max:100'],
        ], [
            'reason.required' => 'Say why the money is going back — it is kept on the record.',
        ]);

        $refund = $this->refunds->request($payment, $data, $request->user());

        return $this->respond($request, $refund, 'Refund requested. It needs an approver\'s decision before any money moves.', 201);
    }

    /**
     * Who may attempt a kind of step, checked before anything else is read
     * from the request. Whether this refund can take that step now — its
     * status, and never your own request — is RefundService's to decide.
     */
    private function mayAttempt(Request $request, array $permissions): void
    {
        abort_unless($request->user()->canAny($permissions), 403, 'You are not allowed to do that with refunds.');
    }

    public function review(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['approve refunds']);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->refunds->startReview($refund, $request->user(), $data['note'] ?? null), 'Marked as under review.');
    }

    public function approve(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['approve refunds']);
        $data =$request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->refunds->approve($refund, $request->user(), $data['note'] ?? null), 'Approved. It can now be paid out.');
    }

    public function reject(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['approve refunds']);
        $data =$request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']], [
            'note.required' => 'Say why it is rejected — the requester sees this.',
        ]);

        return $this->respond($request, $this->refunds->reject($refund, $request->user(), $data['note']), 'Rejected.');
    }

    public function process(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['process refunds']);
        $data =$request->validate([
            'method' => ['nullable', 'string', 'max:100'],
            'note'   => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->respond($request, $this->refunds->markProcessing($refund, $request->user(), $data['method'] ?? null, $data['note'] ?? null), 'Marked as being paid out.');
    }

    public function complete(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['process refunds']);
        $data =$request->validate([
            'reference' => ['required', 'string', 'min:2', 'max:150'],
            'method'    => ['nullable', 'string', 'max:100'],
            'note'      => ['nullable', 'string', 'max:2000'],
        ], [
            'reference.required' => 'Enter the transaction reference that proves the money went back.',
        ]);

        return $this->respond($request, $this->refunds->complete($refund, $request->user(), $data['reference'], $data['method'] ?? null, $data['note'] ?? null), 'Refund completed.');
    }

    public function cancel(Request $request, Refund $refund): JsonResponse
    {
        $this->mayAttempt($request, ['request refunds', 'approve refunds']);
        $data =$request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']], [
            'reason.required' => 'Say why it is cancelled — it is kept on the record.',
        ]);

        return $this->respond($request, $this->refunds->cancel($refund, $request->user(), $data['reason']), 'Refund cancelled.');
    }

    private function respond(Request $request, Refund $refund, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'refund'  => $this->refunds->present($refund->fresh(), $request->user(), withEvents: true),
        ], $status);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = Refund::query()->with(['client:id,client_name,dfid_number', 'payment:id,amount,payment_date', 'requestedBy:id,name']);

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->boolean('mine')) {
            $query->where('requested_by', $request->user()->id);
        }

        $byStatus = (clone $query)->reorder()
            ->select('status')->selectRaw('COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');
        $counts = ['total' => (int) $byStatus->sum(), 'status' => $byStatus->map(fn ($n) => (int) $n)];

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $spill = [
            Refund::STATUS_REQUESTED => 'spill-warning', Refund::STATUS_UNDER_REVIEW => 'spill-in-progress',
            Refund::STATUS_APPROVED => 'spill-running', Refund::STATUS_PROCESSING => 'spill-in-progress',
            Refund::STATUS_COMPLETED => 'spill-completed', Refund::STATUS_REJECTED => 'spill-cancelled',
            Refund::STATUS_CANCELLED => 'spill-hold',
        ];

        return DataTables::of($query)
            ->addColumn('number', fn (Refund $r) => '<button type="button" class="btn btn-link p-0 fw-semibold refund-open" data-id="' . $r->id . '">' . e($r->refund_number) . '</button>')
            ->addColumn('client', fn (Refund $r) => $r->client ? '<a href="' . e(route('clients.show', $r->client_id)) . '#tab-payments">' . e($r->client->client_name) . '</a>' : '—')
            ->addColumn('payment', fn (Refund $r) => $r->payment
                ? '৳' . number_format((float) $r->payment->amount, 2) . '<div style="font-size:.7rem;color:var(--text3)">' . e($r->payment->payment_date?->format('d M Y') ?? '—') . '</div>'
                : '—')
            ->addColumn('amount_fmt', fn (Refund $r) => '<strong>৳' . number_format((float) $r->amount, 2) . '</strong>')
            ->addColumn('status_badge', fn (Refund $r) => '<span class="spill ' . ($spill[$r->status] ?? 'spill-hold') . '">' . e($r->status_label) . '</span>')
            ->addColumn('requested', fn (Refund $r) => e($r->requestedBy->name ?? '—')
                . '<div style="font-size:.7rem;color:var(--text3)">' . e($r->created_at?->format('d M Y, H:i')) . '</div>')
            ->addColumn('actions', fn (Refund $r) => '<button type="button" class="btn btn-sm px-2 py-1 refund-open" data-id="' . $r->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Open"><i class="bi bi-box-arrow-up-right"></i></button>')
            ->rawColumns(['number', 'client', 'payment', 'amount_fmt', 'status_badge', 'requested', 'actions'])
            ->orderColumn('requested', 'created_at $1')
            ->with(['counts' => $counts])
            ->make(true);
    }
}
