<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeRequest\StoreEmployeeRequestRequest;
use App\Models\Client;
use App\Models\EmployeeRequest;
use App\Models\User;
use App\Services\EmployeeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class EmployeeRequestController extends Controller
{
    public function __construct(
        private readonly EmployeeRequestService $service,
    ) {
    }

    public function index(Request $request)
    {
        // Everyone gets the page — EmployeeRequestPolicy::viewAny() is always
        // true. What each person actually sees in it is scoped per-row below.
        $this->authorize('viewAny', EmployeeRequest::class);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);
        // Who a new request can be sent to — anyone but yourself.
        $users = User::where('is_active', true)->where('id', '!=', $request->user()->id)
            ->orderBy('name')->get(['id', 'name']);
        $canCreate = $request->user()->can('create', EmployeeRequest::class);

        return view('requests.index', compact('clients', 'users', 'canCreate'));
    }

    public function store(StoreEmployeeRequestRequest $request): JsonResponse
    {
        $employeeRequest = $this->service->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'request' => $employeeRequest]);
    }

    public function respond(Request $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        $this->authorize('respond', $employeeRequest);

        $employeeRequest->loadMissing('recipients');
        if ($reason = $employeeRequest->respondBlockerFor($request->user())) {
            return response()->json(['message' => $reason], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([EmployeeRequest::STATUS_APPROVED, EmployeeRequest::STATUS_REJECTED])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->respond($employeeRequest, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json(['success' => true, 'request' => $updated]);
    }

    public function forward(Request $request, EmployeeRequest $employeeRequest): JsonResponse
    {
        $this->authorize('forward', $employeeRequest);

        $employeeRequest->loadMissing('recipients');
        if ($reason = $employeeRequest->forwardBlockerFor($request->user())) {
            return response()->json(['message' => $reason], 422);
        }

        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $toUserId = (int) $data['to_user_id'];

        if ($toUserId === (int) $request->user()->id) {
            return response()->json(['message' => 'Choose someone else to forward this to.'], 422);
        }
        if ($employeeRequest->recipients->contains($toUserId)) {
            return response()->json(['message' => 'That person already has this request.'], 422);
        }
        if ((int) $employeeRequest->requested_by === $toUserId) {
            return response()->json(['message' => 'You cannot forward a request back to whoever filed it.'], 422);
        }

        $to = User::findOrFail($toUserId);
        $updated = $this->service->forward($employeeRequest, $request->user(), $to, $data['note'] ?? null);

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
        $user = $request->user();

        $query = EmployeeRequest::query()->with([
            'requestedBy:id,name', 'client:id,client_name', 'recipients:id,name',
            'forwards.fromUser:id,name', 'forwards.toUser:id,name',
        ]);

        // What you may see at all: your own, or one sent to you. "manage
        // requests" plays no part in this any more — see EmployeeRequestPolicy.
        $query->where(function ($q) use ($user) {
            $q->where('requested_by', $user->id)
                ->orWhereHas('recipients', fn ($qq) => $qq->where('users.id', $user->id));
        });

        if ($request->boolean('mine_only')) {
            $query->where('requested_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('subject', fn(EmployeeRequest $r) => e($r->subject))
            ->addColumn('requester', fn(EmployeeRequest $r) => e($r->requestedBy->name ?? '-'))
            ->addColumn('recipients', fn(EmployeeRequest $r) => $this->recipientsColumn($r, $user))
            ->addColumn('client', fn(EmployeeRequest $r) => e($r->client->client_name ?? '-'))
            ->addColumn('status_badge', fn(EmployeeRequest $r) => $this->statusBadgeFor($r, $user))
            ->addColumn('created', fn(EmployeeRequest $r) => $r->created_at->format('d M Y'))
            ->addColumn('actions', fn(EmployeeRequest $r) => $this->actionButtons($r, $user))
            ->rawColumns(['recipients', 'status_badge', 'actions'])
            ->make(true);
    }

    /**
     * The requester sees everyone it went to and each one's own answer, so
     * they can tell who's still holding it up. A recipient sees only their
     * own name — not who else it was sent to or how anyone else answered.
     * Whoever's slot was forwarded at least once shows the whole hand-off
     * chain ("Ahsan -> Moulin -> Salman") instead of just the current holder.
     */
    private function recipientsColumn(EmployeeRequest $r, User $user): string
    {
        if ((int) $r->requested_by === (int) $user->id) {
            return $r->recipients
                ->map(fn ($u) => $this->chainLabel($r, $u->id) . ' <span style="color:var(--text3)">(' . e($u->pivot->status) . ')</span>')
                ->implode(', ');
        }

        $mine = $r->recipients->firstWhere('id', $user->id);

        return $mine ? $this->chainLabel($r, $mine->id) : '-';
    }

    /** "Ahsan -> Moulin -> Salman" for a slot that's been forwarded, or just the one name for a slot that hasn't. */
    private function chainLabel(EmployeeRequest $r, int $currentUserId): string
    {
        $chain = $r->chainFor($currentUserId);

        if (count($chain) <= 1) {
            return e($r->recipients->firstWhere('id', $currentUserId)?->name ?? '');
        }

        $names = User::whereIn('id', $chain)->pluck('name', 'id');

        return collect($chain)->map(fn ($id) => e($names[$id] ?? '?'))->implode(' <i class="bi bi-arrow-right" style="font-size:.6rem"></i> ');
    }

    /**
     * The requester sees the request's overall outcome — worded "Approved by
     * All" once every recipient has, so it reads as unanimous rather than a
     * single person's call. A recipient sees only their own personal answer,
     * never the aggregate or anyone else's.
     */
    private function statusBadgeFor(EmployeeRequest $r, User $user): string
    {
        if ((int) $r->requested_by === (int) $user->id) {
            $label = $r->status === EmployeeRequest::STATUS_APPROVED && $r->recipients->count() > 1
                ? 'Approved by All'
                : $r->status;

            return $this->statusBadge($r->status, $label);
        }

        $mine = $r->recipients->firstWhere('id', $user->id);
        $status = $mine->pivot->status ?? EmployeeRequest::STATUS_PENDING;

        return $this->statusBadge($status, $status);
    }

    private function statusBadge(string $status, ?string $label = null): string
    {
        $map = [
            EmployeeRequest::STATUS_PENDING => 'spill-pending',
            EmployeeRequest::STATUS_APPROVED => 'spill-approved',
            EmployeeRequest::STATUS_REJECTED => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$status] ?? 'spill-pending') . '">' . e($label ?? $status) . '</span>';
    }

    private function actionButtons(EmployeeRequest $r, User $user): string
    {
        $isRecipient = $r->recipients->contains($user->id);
        $html = '<button class="btn btn-sm px-2 py-1 req-view" data-id="' . $r->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></button> ';

        if ($isRecipient && $r->respondBlockerFor($user) === null) {
            $html .= '<button class="btn btn-sm px-2 py-1 req-approve" data-id="' . $r->id . '" style="background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);color:#059669" title="Approve"><i class="bi bi-check-lg"></i></button> '
                . '<button class="btn btn-sm px-2 py-1 req-reject" data-id="' . $r->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Reject"><i class="bi bi-x-lg"></i></button> '
                . '<button class="btn btn-sm px-2 py-1 req-forward" data-id="' . $r->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Forward to someone else"><i class="bi bi-send"></i></button> ';
        }

        if ($r->status === EmployeeRequest::STATUS_PENDING && $r->requested_by === $user->id) {
            $html .= '<button class="btn btn-sm px-2 py-1 req-delete" data-id="' . $r->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
        }

        return $html;
    }
}
