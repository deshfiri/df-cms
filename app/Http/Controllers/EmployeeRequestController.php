<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeRequest\StoreEmployeeRequestRequest;
use App\Models\Client;
use App\Models\EmployeeRequest;
use App\Services\EmployeeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class EmployeeRequestController extends Controller
{
    public function __construct(
        private readonly EmployeeRequestService $service,
    ) {}

    public function index(Request $request) //first kick
    {
        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients   = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);
        $canManage = $request->user()->can('manage requests');

        return view('requests.index', compact('clients', 'canManage'));
    }

    public function store(StoreEmployeeRequestRequest $request): JsonResponse
    {
        $employeeRequest = $this->service->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'request' => $employeeRequest]);
    }

    public function respond(Request $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        $this->authorize('respond', $employeeRequest);

        if ($employeeRequest->status !== EmployeeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This request has already been reviewed.'], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([EmployeeRequest::STATUS_APPROVED, EmployeeRequest::STATUS_REJECTED])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->respond($employeeRequest, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json(['success' => true, 'request' => $updated]);
    }

    public function destroy(EmployeeRequest $employeeRequest): JsonResponse
    {
        $this->authorize('delete', $employeeRequest);
        $this->service->delete($employeeRequest);

        return response()->json(['success' => true]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $user      = $request->user();
        $canManage = $user->can('manage requests');

        $query = EmployeeRequest::query()->with(['requestedBy:id,name', 'client:id,client_name']);

        if (!$canManage) {
            $query->where('requested_by', $user->id);
        } elseif ($request->boolean('mine_only')) {
            $query->where('requested_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('subject', fn (EmployeeRequest $r) => e($r->subject))
            ->addColumn('requester', fn (EmployeeRequest $r) => e($r->requestedBy->name ?? '-'))
            ->addColumn('client', fn (EmployeeRequest $r) => e($r->client->client_name ?? '-'))
            ->addColumn('status_badge', fn (EmployeeRequest $r) => $this->statusBadge($r->status))
            ->addColumn('created', fn (EmployeeRequest $r) => $r->created_at->format('d M Y'))
            ->addColumn('actions', fn (EmployeeRequest $r) => $this->actionButtons($r, $user, $canManage))
            ->rawColumns(['status_badge', 'actions'])
            ->make(true);
    }

    private function statusBadge(string $status): string
    {
        $map = [
            EmployeeRequest::STATUS_PENDING  => 'spill-pending',
            EmployeeRequest::STATUS_APPROVED => 'spill-approved',
            EmployeeRequest::STATUS_REJECTED => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$status] ?? 'spill-pending') . '">' . e($status) . '</span>';
    }

    private function actionButtons(EmployeeRequest $r, $user, bool $canManage): string
    {
        $html = '<button class="btn btn-sm px-2 py-1 req-view" data-id="' . $r->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></button> ';

        if ($canManage && $r->status === EmployeeRequest::STATUS_PENDING) {
            $html .= '<button class="btn btn-sm px-2 py-1 req-approve" data-id="' . $r->id . '" style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);color:#059669" title="Approve"><i class="bi bi-check-lg"></i></button> '
                . '<button class="btn btn-sm px-2 py-1 req-reject" data-id="' . $r->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Reject"><i class="bi bi-x-lg"></i></button> ';
        }

        if ($r->status === EmployeeRequest::STATUS_PENDING && ($r->requested_by === $user->id || $canManage)) {
            $html .= '<button class="btn btn-sm px-2 py-1 req-delete" data-id="' . $r->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
        }

        return $html;
    }
}
