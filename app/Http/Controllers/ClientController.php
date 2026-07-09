<?php

namespace App\Http\Controllers;

use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Models\Category;
use App\Models\Client;
use App\Models\User;
use App\Repositories\Contracts\ClientRepositoryInterface;
use App\Services\ClientOwnershipService;
use App\Services\ClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class ClientController extends Controller
{
    public function __construct(
        private readonly ClientService              $clientService,
        private readonly ClientRepositoryInterface  $clientRepo,
        private readonly ClientOwnershipService     $ownershipService,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $categories   = Category::active()->orderBy('name')->get();
        $users        = User::where('is_active', true)->orderBy('name')->get();
        $statuses     = Client::$statuses;

        $countsQuery = Client::whereNull('deleted_at');
        if (!$request->user()->hasRole('Super Admin')) {
            $userId = $request->user()->id;
            $countsQuery->where(function ($q) use ($userId) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', $userId);
            });
        }
        $statusCounts = $countsQuery
            ->selectRaw('client_status, COUNT(*) as cnt')
            ->groupBy('client_status')
            ->pluck('cnt', 'client_status');
        $totalClients = $statusCounts->sum();

        return view('clients.index', compact('categories', 'users', 'statuses', 'statusCounts', 'totalClients'));
    }

    public function quickView(Client $client): JsonResponse
    {
        $client->load([
            'category:id,name',
            'assignedUser:id,name',
            'stageProgress',
            'productUpdates' => fn ($q) => $q->latest()->limit(1),
            'activityLogs'   => fn ($q) => $q->with('user:id,name')->latest()->limit(5),
        ]);

        $totalStages     = \App\Models\WorkflowStage::where('status', true)->count();
        $completedStages = $client->stageProgress->where('is_completed', true)->count();
        $progress        = $totalStages > 0 ? (int) round(($completedStages / $totalStages) * 100) : 0;

        return response()->json([
            'id'          => $client->id,
            'dfid'        => $client->dfid_number,
            'name'        => $client->client_name,
            'brand'       => $client->brand_name,
            'website'          => $client->website,
            'website_url'      => $client->website_url,
            'designs_link'     => $client->designs_link,
            'designs_link_url' => $client->designs_link_url,
            'status'      => $client->client_status,
            'doc_status'  => $client->doc_status,
            'category'    => $client->category?->name,
            'joined'      => $client->joining_date?->format('d M Y'),
            'assigned'    => $client->assignedUser?->name,
            'remarks'     => $client->remarks,
            'progress'    => $progress,
            'done_stages' => $completedStages,
            'total_stages'=> $totalStages,
            'latest_update' => $client->productUpdates->first() ? [
                'status' => $client->productUpdates->first()->status,
                'time'   => $client->productUpdates->first()->created_at->diffForHumans(),
            ] : null,
            'activities'  => $client->activityLogs->map(fn ($l) => [
                'action' => $l->action,
                'user'   => $l->user?->name ?? 'System',
                'time'   => $l->created_at->diffForHumans(),
            ])->toArray(),
            'show_url' => route('clients.show', $client),
            'edit_url' => route('clients.edit', $client),
        ]);
    }

    public function activity(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $logs = $client->activityLogs()->with('user:id,name')->get();

        return response()->json([
            'data' => $logs->map(fn ($log) => [
                'module'     => $log->module,
                'action'     => $log->action,
                'user'       => $log->user?->name,
                'created_at' => $log->created_at->format('d M Y H:i'),
                'ip_address' => $log->ip_address,
            ]),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Client::class);
        $categories = Category::active()->orderBy('name')->get();
        $users      = User::orderBy('name')->get();
        $statuses   = Client::$statuses;
        $nextDfid   = $this->clientRepo->nextDfidNumber();

        return view('clients.create', compact('categories', 'users', 'statuses', 'nextDfid'));
    }

    public function store(StoreClientRequest $request)
    {
        $client = $this->clientService->create($request->validated());

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Client created.', 'id' => $client->id]);
        }

        return redirect()->route('clients.show', $client)->with('success', 'Client created successfully.');
    }

    public function show(Client $client)
    {
        $this->authorize('view', $client);
        $client = $this->clientRepo->findById($client->id);
        $stages        = \App\Models\WorkflowStage::active()->get();
        $progress_map  = $client->stageProgress->keyBy('stage_id');
        $documentTypes = \App\Models\DocumentType::active()->get();
        $users         = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        return view('clients.show', compact('client', 'stages', 'progress_map', 'documentTypes', 'users'));
    }

    public function edit(Client $client)
    {
        $this->authorize('update', $client);
        $categories = Category::active()->orderBy('name')->get();
        $users      = User::orderBy('name')->get();
        $statuses   = Client::$statuses;

        return view('clients.edit', compact('client', 'categories', 'users', 'statuses'));
    }

    public function update(UpdateClientRequest $request, Client $client)
    {
        $updated = $this->clientService->update($client, $request->validated());

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Client updated.']);
        }

        return redirect()->route('clients.show', $updated)->with('success', 'Client updated successfully.');
    }

    public function destroy(Request $request, Client $client)
    {
        $this->authorize('delete', $client);
        $this->clientService->delete($client);

        if ($request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Client deleted.']);
        }

        return redirect()->route('clients.index')->with('success', 'Client deleted.');
    }

    public function updateStatus(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);
        $request->validate(['status' => ['required', \Illuminate\Validation\Rule::in(Client::$statuses)]]);
        $updated = $this->clientService->updateStatus($client, $request->status);

        return response()->json(['success' => true, 'status' => $updated->client_status]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $this->authorize('delete', Client::class);
        $ids = $request->input('ids', []);
        Client::whereIn('id', $ids)->each(fn ($c) => $this->clientService->delete($c));

        return response()->json(['success' => true, 'message' => count($ids) . ' clients deleted.']);
    }

    public function bulkTerminate(Request $request): JsonResponse
    {
        $this->authorize('terminate', Client::class);

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:clients,id'],
        ]);

        $clients = Client::whereIn('id', $data['ids'])->get();
        $clients->each(fn (Client $c) => $this->clientService->updateStatus($c, 'Terminated'));

        return response()->json(['success' => true, 'message' => $clients->count() . ' clients terminated.']);
    }

    public function transferOwnership(Request $request, Client $client): JsonResponse
    {
        $this->authorize('transfer', $client);

        $data = $request->validate([
            'new_owner_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$client->assigned_to])],
            'note'         => ['nullable', 'string', 'max:1000'],
        ], ['new_owner_id.not_in' => 'Client is already assigned to this user.']);

        $newOwner = User::where('is_active', true)->findOrFail($data['new_owner_id']);
        $updated  = $this->ownershipService->transfer($client, $newOwner, Auth::user(), $data['note'] ?? null);

        return response()->json([
            'success'       => true,
            'assigned_to'   => $updated->assigned_to,
            'assigned_name' => $newOwner->name,
        ]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $this->authorize('bulkAssign', Client::class);

        $data = $request->validate([
            'ids'          => ['required', 'array', 'min:1'],
            'ids.*'        => ['integer', 'exists:clients,id'],
            'new_owner_id' => ['required', 'integer', 'exists:users,id'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ]);

        $newOwner = User::where('is_active', true)->findOrFail($data['new_owner_id']);
        $count    = $this->ownershipService->bulkAssign($data['ids'], $newOwner, Auth::user(), $data['note'] ?? null);

        return response()->json(['success' => true, 'message' => "{$count} clients assigned to {$newOwner->name}."]);
    }

    public function ownershipHistory(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $history = $client->ownershipTransfers()
            ->with(['previousOwner:id,name', 'newOwner:id,name', 'transferredBy:id,name'])
            ->get();

        return response()->json(['data' => $history->map(fn ($h) => [
            'from' => $h->previousOwner?->name ?? 'Unassigned',
            'to'   => $h->newOwner?->name ?? '—',
            'by'   => $h->transferredBy?->name ?? 'System',
            'note' => $h->note,
            'date' => $h->created_at->format('d M Y H:i'),
        ])]);
    }

    // ── DataTable ────────────────────────────────────────────────────────────

    private function dataTable(Request $request): JsonResponse
    {
        $query = $this->clientRepo->query();

        // Super Admin sees everything; everyone else only sees clients
        // assigned to them plus clients that aren't assigned to anyone yet.
        if (!$request->user()->hasRole('Super Admin')) {
            $userId = $request->user()->id;
            $query->where(function ($q) use ($userId) {
                $q->whereNull('assigned_to')->orWhere('assigned_to', $userId);
            });
        }

        if ($request->filled('status')) {
            $query->where('client_status', $request->status);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('assigned_to')) {
            if ($request->assigned_to === 'none') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $request->assigned_to);
            }
        }
        if ($request->boolean('no_update')) {
            $query->whereIn('client_status', ['Running', 'Warning'])
                  ->whereDoesntHave('productUpdates', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)));
        }
        if ($request->filled('id_from')) {
            $query->where('id', '>=', (int) $request->id_from);
        }
        if ($request->filled('id_to')) {
            $query->where('id', '<=', (int) $request->id_to);
        }
        if ($request->filled('dfid_from')) {
            $query->where('dfid_number', '>=', trim($request->dfid_from));
        }
        if ($request->filled('dfid_to')) {
            $query->where('dfid_number', '<=', trim($request->dfid_to));
        }

        // Global search handled here (bypasses Yajra column-based SQL search which fails on relations)
        $searchTerm = $request->input('search.value');
        if (!empty(trim($searchTerm))) {
            $query->search(trim($searchTerm));
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('dfid', fn ($c) => '<span class="badge" style="background:var(--surface2);color:var(--text2);border:1px solid var(--border);font-family:monospace;font-size:.68rem">' . e($c->dfid_number) . '</span>')
            ->addColumn('client', fn ($c) => '<div style="font-size:.8rem;font-weight:600;color:var(--text)">' . e($c->client_name) . '</div>'
                . ($c->brand_name ? '<div style="font-size:.71rem;color:var(--text3)">' . e($c->brand_name) . '</div>' : ''))
            ->addColumn('category', fn ($c) => e($c->category?->name ?? '-'))
            ->addColumn('website', fn ($c) => $c->website ? '<a href="' . e($c->website_url) . '" target="_blank" class="text-truncate d-inline-block" style="max-width:130px">' . e($c->website) . '</a>' : '-')
            ->addColumn('joining', fn ($c) => $c->joining_date?->format('d M Y') ?? '-')
            ->addColumn('progress', fn ($c) => $this->progressBadge($c))
            ->addColumn('client_status_badge', fn ($c) => $this->statusBadge($c, $request->user()))
            ->addColumn('product_status', fn ($c) => $c->latestProductStatus ? '<small class="badge bg-info">' . e($c->latestProductStatus) . '</small>' : '-')
            ->addColumn('payment_status', fn ($c) => $this->paymentBadge($c->latestPaymentStatus))
            ->addColumn('actions', fn ($c) => $this->actionButtons($c, $request->user()))
            ->rawColumns(['dfid', 'client', 'website', 'progress', 'client_status_badge', 'product_status', 'payment_status', 'actions'])
            ->make(true);
    }

    private function progressBadge(Client $client): string
    {
        $total     = \App\Models\WorkflowStage::where('status', true)->count();
        $completed = $client->completed_stages_count ?? 0;
        $pct       = $total > 0 ? (int) round(($completed / $total) * 100) : 0;
        $color     = $pct === 100 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');

        return '<div class="d-flex align-items-center gap-1">
            <div class="progress flex-grow-1" style="height:6px">
              <div class="progress-bar bg-' . $color . '" style="width:' . $pct . '%"></div>
            </div>
            <small>' . $pct . '%</small>
          </div>';
    }

    private function statusBadge(Client $client, User $user): string
    {
        $cls = [
            'Running'    => 'spill-running',
            'Warning'    => 'spill-warning',
            'Completed'  => 'spill-completed',
            'Hold'       => 'spill-hold',
            'Cancelled'  => 'spill-cancelled',
            'Terminated' => 'spill-terminated',
        ];
        $status = $client->client_status;
        $pillClass = $cls[$status] ?? 'spill-hold';

        $canTerminate = $user->can('terminate', Client::class);

        $items = '';
        foreach (Client::$statuses as $s) {
            if ($s === 'Terminated' && !$canTerminate) {
                continue;
            }
            $dot = match($s) {
                'Running'    => '#059669',
                'Warning'    => '#d97706',
                'Completed'  => '#2563eb',
                'Hold'       => '#64748b',
                'Cancelled'  => '#dc2626',
                'Terminated' => '#7f1d1d',
                default      => '#94a3b8',
            };
            $items .= '<div class="sd-item" data-status="' . e($s) . '">'
                    . '<span class="sd-dot" style="background:' . $dot . '"></span>'
                    . e($s) . '</div>';
        }

        return '<div class="status-dd" data-client-id="' . $client->id . '">'
             . '<span class="spill ' . $pillClass . ' status-trigger" style="cursor:pointer">' . e($status) . ' <i class="bi bi-chevron-down" style="font-size:.55rem"></i></span>'
             . '<div class="sd-menu">' . $items . '</div>'
             . '</div>';
    }

    private function paymentBadge(?string $status): string
    {
        if (!$status) {
            return '-';
        }
        $map = ['Paid' => 'success', 'Partial' => 'warning', 'Unpaid' => 'danger'];

        return '<span class="badge bg-' . ($map[$status] ?? 'secondary') . '">' . e($status) . '</span>';
    }

    private function actionButtons(Client $client, User $user): string
    {
        $html = '<div class="d-flex gap-1 justify-content-end row-acts">'
             . '<a href="' . route('clients.show', $client) . '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></a>';

        if ($user->can('update', $client)) {
            $html .= '<a href="' . route('clients.edit', $client) . '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Edit"><i class="bi bi-pencil"></i></a>';
        }

        if ($user->can('delete', $client)) {
            $html .= '<button class="btn btn-sm px-2 py-1 btn-delete" data-id="' . $client->id . '" style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#dc2626" title="Delete"><i class="bi bi-trash"></i></button>';
        }

        return $html . '</div>';
    }
}
