<?php

namespace App\Http\Controllers;

use App\Models\ClientCorrectionRequest;
use App\Services\ClientCorrectionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class ClientCorrectionRequestController extends Controller
{
    public function __construct(
        private readonly ClientCorrectionRequestService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('manage clients'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        return view('correction-requests.index');
    }

    public function respond(Request $request, ClientCorrectionRequest $correctionRequest): JsonResponse
    {
        abort_unless($request->user()->can('manage clients'), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['Approved', 'Rejected', 'Need More Information'])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->respond($correctionRequest, $data['status'], $data['note'] ?? null, Auth::user());

        return response()->json(['success' => true, 'data' => $updated]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = ClientCorrectionRequest::query()->with(['client:id,client_name', 'submittedBy:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (ClientCorrectionRequest $c) => e($c->client->client_name ?? '-'))
            ->addColumn('field', fn (ClientCorrectionRequest $c) => e($c->field_label))
            ->addColumn('created', fn (ClientCorrectionRequest $c) => $c->created_at->format('d M Y'))
            ->make(true);
    }
}
