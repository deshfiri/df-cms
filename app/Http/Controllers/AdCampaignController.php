<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdCampaign\StoreAdCampaignReportRequest;
use App\Http\Requests\AdCampaign\StoreAdCampaignRequest;
use App\Models\AdCampaign;
use App\Models\AdCampaignDailyReport;
use App\Models\Client;
use App\Models\User;
use App\Services\AdCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class AdCampaignController extends Controller
{
    public function __construct(
        private readonly AdCampaignService $service,
    ) {
    }

    public function index(Client $client, Request $request): JsonResponse
    {
        $this->authorize('viewAny', AdCampaign::class);

        $campaigns = $client->adCampaigns()
            ->with(['assignedUser:id,name', 'brand:id,name'])
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->get()
            ->map(fn(AdCampaign $c) => $this->present($c));

        return response()->json(['data' => $campaigns]);
    }

    public function store(StoreAdCampaignRequest $request, Client $client): JsonResponse
    {
        $this->authorize('create', AdCampaign::class);

        $campaign = $this->service->create($client, $request->validated());

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    public function show(Client $client, AdCampaign $campaign)
    {
        abort_if($campaign->client_id !== $client->id, 404);
        $this->authorize('view', $campaign);

        $campaign->load(['assignedUser:id,name', 'dailyReports', 'brand:id,name']);

        $chart = [
            'labels' => $campaign->dailyReports->pluck('report_date')->map(fn($d) => $d->format('d M'))->values(),
            'spend' => $campaign->dailyReports->pluck('spend')->map(fn($v) => (float) $v)->values(),
            'sales' => $campaign->dailyReports->pluck('sales')->map(fn($v) => (float) $v)->values(),
            'leads' => $campaign->dailyReports->pluck('leads')->values(),
            'orders' => $campaign->dailyReports->pluck('orders')->values(),
        ];

        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('ads.show', compact('campaign', 'chart', 'users'));
    }

    public function update(StoreAdCampaignRequest $request, Client $client, AdCampaign $campaign): JsonResponse
    {
        abort_if($campaign->client_id !== $client->id, 404);
        $this->authorize('update', $campaign);

        $updated = $this->service->update($campaign, $request->validated());

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function destroy(Client $client, AdCampaign $campaign): JsonResponse
    {
        abort_if($campaign->client_id !== $client->id, 404);
        $this->authorize('delete', $campaign);

        $this->service->delete($campaign);

        return response()->json(['success' => true]);
    }

    public function assign(Request $request, Client $client, AdCampaign $campaign): JsonResponse
    {
        abort_if($campaign->client_id !== $client->id, 404);
        $this->authorize('assign', AdCampaign::class);

        $data = $request->validate([
            'new_assignee_id' => ['required', 'exists:users,id', Rule::notIn([$campaign->assigned_to])],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['new_assignee_id.not_in' => 'Campaign is already assigned to this user.']);

        $newAssignee = User::where('is_active', true)->findOrFail($data['new_assignee_id']);
        $updated = $this->service->assign($campaign, $newAssignee, $request->user(), $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function storeReport(StoreAdCampaignReportRequest $request, Client $client, AdCampaign $campaign): JsonResponse
    {
        abort_if($campaign->client_id !== $client->id, 404);
        $this->authorize('update', $campaign);

        $report = $this->service->upsertReport($campaign, $request->validated());

        return response()->json(['success' => true, 'data' => $report]);
    }

    public function destroyReport(Client $client, AdCampaign $campaign, AdCampaignDailyReport $report): JsonResponse
    {
        abort_if($campaign->client_id !== $client->id || $report->ad_campaign_id !== $campaign->id, 404);
        $this->authorize('update', $campaign);

        $this->service->deleteReport($report);

        return response()->json(['success' => true]);
    }

    /** Standalone "all campaigns across all clients" page, reachable from the sidebar. */
    public function all(Request $request)
    {
        $this->authorize('viewAny', AdCampaign::class);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $clients = Client::withoutTrashed()->orderBy('client_name')->get(['id', 'client_name', 'dfid_number']);
        $users = User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // The campaign figures are scoped by the soft-delete global scope, but
        // the report sums are not: a deleted campaign's spend would keep
        // counting toward the headline totals while its row was gone from the
        // table below. whereHas re-applies the same scope.
        $liveReports = fn () => AdCampaignDailyReport::whereHas('campaign');

        $totals = [
            'active_count' => AdCampaign::where('status', 'Active')->count(),
            'total_budget' => (float) AdCampaign::sum('budget'),
            'total_spend' => (float) $liveReports()->sum('spend'),
            'total_sales' => (float) $liveReports()->sum('sales'),
        ];
        $totals['overall_roas'] = $totals['total_spend'] > 0 ? round($totals['total_sales'] / $totals['total_spend'], 2) : null;

        return view('ads.index', compact('clients', 'users', 'totals'));
    }

    public function storeAny(Request $request): JsonResponse
    {
        $this->authorize('create', AdCampaign::class);

        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'brand_id' => ['nullable', Rule::exists('brands', 'id')->where(fn ($q) => $q->where('client_id', $request->input('client_id')))],
            'name' => ['required', 'string', 'max:191'],
            'platform' => ['nullable', Rule::in(AdCampaign::$platforms)],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(AdCampaign::$statuses)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'remarks' => ['nullable', 'string'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        unset($data['client_id']);

        $campaign = $this->service->create($client, $data);

        return response()->json(['success' => true, 'data' => $campaign->load('client:id,client_name,dfid_number')]);
    }

    public function assignAny(Request $request, AdCampaign $campaign): JsonResponse
    {
        $this->authorize('assign', AdCampaign::class);

        $data = $request->validate([
            'new_assignee_id' => ['required', 'exists:users,id', Rule::notIn([$campaign->assigned_to])],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['new_assignee_id.not_in' => 'Campaign is already assigned to this user.']);

        $newAssignee = User::where('is_active', true)->findOrFail($data['new_assignee_id']);
        $updated = $this->service->assign($campaign, $newAssignee, $request->user(), $data['note'] ?? null);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function destroyAny(AdCampaign $campaign): JsonResponse
    {
        $this->authorize('delete', $campaign);

        $this->service->delete($campaign);

        return response()->json(['success' => true]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = AdCampaign::query()
            ->withSum('dailyReports as spend_sum', 'spend')
            ->withSum('dailyReports as sales_sum', 'sales')
            ->withSum('dailyReports as leads_sum', 'leads')
            ->withSum('dailyReports as orders_sum', 'orders')
            ->with(['client:id,client_name,dfid_number', 'assignedUser:id,name', 'brand:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->brand_id);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn(AdCampaign $c) => e($c->client->client_name ?? '—'))
            ->addColumn('brand', fn(AdCampaign $c) => e($c->brand->name ?? '—'))
            ->addColumn('assigned', fn(AdCampaign $c) => e($c->assignedUser->name ?? 'Unassigned'))
            ->addColumn('status_badge', fn(AdCampaign $c) => $this->statusBadge($c->status))
            ->addColumn('budget_fmt', fn(AdCampaign $c) => $c->budget !== null ? '৳' . number_format((float) $c->budget, 2) : '—')
            ->addColumn('spend_fmt', fn(AdCampaign $c) => '৳' . number_format((float) $c->spend_sum, 2))
            ->addColumn('roas_fmt', function (AdCampaign $c) {
                $spend = (float) $c->spend_sum;
                $sales = (float) $c->sales_sum;

                return $spend > 0 ? number_format($sales / $spend, 2) : '—';
            })
            ->addColumn('actions', function (AdCampaign $c) use ($request) {
                $html = '<a href="' . route('clients.ads.show', [$c->client_id, $c->id]) . '" class="btn btn-sm px-2 py-1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></a>';

                // Deleting a campaign discards its daily reports with it, so it
                // stays with Super Admin / Manager — the same rule the policy
                // enforces on the endpoint.
                if ($request->user()->can('delete', $c)) {
                    $html .= ' <button class="btn btn-sm px-2 py-1 btn-outline-danger campaign-delete"'
                        . ' data-id="' . $c->id . '"'
                        . ' data-name="' . e($c->name) . '"'
                        . ' title="Delete campaign"><i class="bi bi-trash"></i></button>';
                }

                return $html;
            })
            ->rawColumns(['status_badge', 'actions'])
            ->make(true);
    }

    private function statusBadge(string $status): string
    {
        $map = [
            'Active' => 'spill-running',
            'Paused' => 'spill-warning',
            'Completed' => 'spill-completed',
            'Cancelled' => 'spill-cancelled',
        ];

        return '<span class="spill ' . ($map[$status] ?? 'spill-hold') . '">' . e($status) . '</span>';
    }

    private function present(AdCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'platform' => $c->platform,
            'status' => $c->status,
            'assigned_name' => $c->assignedUser->name ?? null,
            'assigned_to' => $c->assigned_to,
            'brand_id' => $c->brand_id,
            'brand_name' => $c->brand->name ?? null,
            'budget' => $c->budget,
            'total_spend' => $c->total_spend,
            'total_sales' => $c->total_sales,
            'roas' => $c->roas,
            'cpl' => $c->cpl,
            'cpa' => $c->cpa,
            'budget_remaining' => $c->budget_remaining,
        ];
    }
}
