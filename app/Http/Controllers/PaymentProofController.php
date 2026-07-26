<?php

namespace App\Http\Controllers;

use App\Models\PaymentProofSubmission;
use App\Services\PaymentProofService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class PaymentProofController extends Controller
{
    public function __construct(
        private readonly PaymentProofService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('manage payments'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        return view('payment-proofs.index');
    }

    public function verify(Request $request, PaymentProofSubmission $proof): JsonResponse
    {
        abort_unless($request->user()->can('manage payments'), 403);

        if ($proof->status !== PaymentProofSubmission::STATUS_PENDING) {
            return response()->json(['message' => 'This submission has already been reviewed.'], 422);
        }

        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $updated = $this->service->verify($proof, $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function reject(Request $request, PaymentProofSubmission $proof): JsonResponse
    {
        abort_unless($request->user()->can('manage payments'), 403);

        if ($proof->status !== PaymentProofSubmission::STATUS_PENDING) {
            return response()->json(['message' => 'This submission has already been reviewed.'], 422);
        }

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $updated = $this->service->reject($proof, $data['note']);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = PaymentProofSubmission::query()->with(['client:id,client_name', 'submittedBy:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (PaymentProofSubmission $p) => e($p->client->client_name ?? '-'))
            ->addColumn('submitted_by', fn (PaymentProofSubmission $p) => e($p->submittedBy->name ?? '-'))
            ->addColumn('amount', fn (PaymentProofSubmission $p) => number_format((float) $p->amount_claimed, 2))
            ->addColumn('created', fn (PaymentProofSubmission $p) => $p->created_at->format('d M Y'))
            ->make(true);
    }
}
