<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\ClientOwnershipTransfer;
use App\Models\ClientStageProgress;
use App\Models\EmployeeRequest;
use App\Models\ImportLog;
use App\Models\Payment;
use App\Models\ProductUpdate;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\WorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    private const DEPARTMENT_ROLES = ['Sales', 'Document', 'Design', 'Website', 'Product', 'Marketing', 'Support'];

    /** Pipeline segment label => the workflow_stages.code values it aggregates. */
    private const PIPELINE_SEGMENTS = [
        'Deal' => ['deal_completed', 'agreement_signed'],
        'Meeting' => ['meeting_scheduled'],
        'Documents' => ['documents_collected', 'business_info_submitted'],
        'Design' => ['brand_name_finalized', 'logo_design', 'banner_design'],
        'Website' => ['website_development', 'website_approved'],
        'Products' => ['product_sourcing', 'product_upload'],
        'Marketing' => ['facebook_page_setup', 'marketing_content_creation', 'video_content_creation', 'marketing_launch'],
        'Support' => ['ongoing_support', 'client_active', 'deal_closed'],
    ];

    /** Department => the section label shown on that team's dashboard, matching how each team actually talks about their queue. */
    private const DEPARTMENT_SECTION_LABELS = [
        'Sales' => 'Deals & Meetings',
        'Document' => 'Document Queue',
        'Design' => 'Design Queue',
        'Website' => 'Website Tasks',
        'Product' => 'Product Queue',
        'Marketing' => 'Campaign Tasks',
        'Support' => 'Active Client Support',
    ];

    public function __construct(
        private readonly WorkflowService $workflowService,
    ) {
    }

    public function index()
    {
        $user = Auth::user();

        if (!$user->hasRole(['Super Admin', 'Manager'])) {
            return $this->departmentDashboard($user);
        }

        // ── Status counts (single query) ──────────────────────────────
        $rawStatus = Client::withoutTrashed()
            ->selectRaw('client_status, COUNT(*) as cnt')
            ->groupBy('client_status')
            ->pluck('cnt', 'client_status')
            ->toArray();

        $statusCounts = [
            'Running' => $rawStatus['Running'] ?? 0,
            'Warning' => $rawStatus['Warning'] ?? 0,
            'Completed' => $rawStatus['Completed'] ?? 0,
            'Hold' => $rawStatus['Hold'] ?? 0,
            'Cancelled' => $rawStatus['Cancelled'] ?? 0,
        ];
        $total = array_sum($statusCounts);

        // ── Unassigned clients (ownership feature) ─────────────────────
        $unassignedClientCount = Client::withoutTrashed()
            ->whereNull('assigned_to')
            ->whereIn('client_status', ['Running', 'Warning'])
            ->count();

        // ── Today's metrics ───────────────────────────────────────────
        $todayUpdates = ProductUpdate::whereDate('created_at', today())->count();

        $todayPayments = Payment::whereDate('payment_date', today())
            ->where('status', 'Paid')
            ->sum('amount');

        $todayPaymentCount = Payment::whereDate('payment_date', today())
            ->where('status', 'Paid')
            ->count();

        // ── Pending / at-risk ─────────────────────────────────────────
        $pendingPayments = Payment::where('status', 'Unpaid')->count();
        $pendingPaymentAmount = Payment::where('status', 'Unpaid')->sum('amount');

        // Clients without any product update in last 30 days
        $activeClientIds = Client::withoutTrashed()
            ->whereIn('client_status', ['Running', 'Warning'])
            ->pluck('id');

        $updatedRecently = ProductUpdate::whereIn('client_id', $activeClientIds)
            ->whereDate('created_at', '>=', now()->subDays(30))
            ->distinct('client_id')
            ->pluck('client_id');

        $clientsWithoutUpdate = $activeClientIds->count() - $updatedRecently->count();

        // Clients with pending workflow stages (progress < 100%)
        $pendingWorkflow = Client::withoutTrashed()
            ->whereIn('client_status', ['Running', 'Warning'])
            ->whereHas('stageProgress', fn($q) => $q->where('is_completed', false))
            ->count();

        // ── Payment summary ───────────────────────────────────────────
        $thisMonthPayments = Payment::where('status', 'Paid')
            ->whereMonth('payment_date', now()->month)
            ->whereYear('payment_date', now()->year)
            ->sum('amount');

        $lastMonthPayments = Payment::where('status', 'Paid')
            ->whereMonth('payment_date', now()->subMonth()->month)
            ->whereYear('payment_date', now()->subMonth()->year)
            ->sum('amount');

        $paymentGrowth = $lastMonthPayments > 0
            ? round((($thisMonthPayments - $lastMonthPayments) / $lastMonthPayments) * 100, 1)
            : 0;

        // ── Recent data ───────────────────────────────────────────────
        $recent = Client::with('category')
            ->withoutTrashed()
            ->latest()
            ->limit(8)
            ->get();

        $recentImports = ImportLog::with('user')
            ->latest()
            ->limit(5)
            ->get();

        $recentActivities = ActivityLog::with('user', 'client')
            ->latest()
            ->limit(12)
            ->get();

        $upcomingMeetings = ClientMeeting::with('client:id,client_name,dfid_number')
            ->upcoming()
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get();

        $scheduledMeetingsCount = ClientMeeting::where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->count();

        $todayMeetingsCount = ClientMeeting::today()->count();

        // ── Top employees by assigned clients ─────────────────────────
        $topEmployees = User::select('users.id', 'users.name')
            ->selectRaw('COUNT(clients.id) as client_count')
            ->leftJoin('clients', function ($j) {
                $j->on('clients.assigned_to', '=', 'users.id')
                    ->whereNull('clients.deleted_at')
                    ->whereIn('clients.client_status', ['Running', 'Warning']);
            })
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('client_count')
            ->limit(5)
            ->get();

        // ── Recent ownership transfers ─────────────────────────────────
        $recentTransfers = ClientOwnershipTransfer::with([
            'client:id,client_name,dfid_number',
            'previousOwner:id,name',
            'newOwner:id,name',
            'transferredBy:id,name',
        ])
            ->latest()
            ->limit(8)
            ->get();

        // ── Charts (cached for 10 minutes) ───────────────────────────
        $monthlyData = Cache::remember('dash.monthly_clients', 600, fn() => $this->monthlyClientData());
        $monthlyPayData = Cache::remember('dash.monthly_payments', 600, fn() => $this->monthlyPaymentData());
        $categoryData = Cache::remember('dash.category_dist', 600, fn() => $this->categoryDistribution());
        $workflowData = Cache::remember('dash.workflow_completion', 600, fn() => $this->workflowCompletionData());

        // ── Workflow-focused top area ─────────────────────────────────
        $delayedCount = Cache::remember('dash.delayed_count', 600, fn() => $this->delayedClientCount());
        $pipeline = Cache::remember('dash.pipeline_segments', 600, fn() => $this->pipelineSegments());

        $myTasks = Task::with('client:id,client_name,dfid_number')
            ->where('assigned_to', $user->id)
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        // ── Pending employee requests (Super Admin / Manager only) ─────
        $pendingRequests = EmployeeRequest::with('requestedBy:id,name')
            ->pending()
            ->latest()
            ->limit(5)
            ->get();
        $pendingRequestCount = EmployeeRequest::pending()->count();

        return view('dashboard', compact(
            'statusCounts',
            'total',
            'todayUpdates',
            'todayPayments',
            'todayPaymentCount',
            'pendingPayments',
            'pendingPaymentAmount',
            'clientsWithoutUpdate',
            'pendingWorkflow',
            'thisMonthPayments',
            'lastMonthPayments',
            'paymentGrowth',
            'recent',
            'recentImports',
            'recentActivities',
            'topEmployees',
            'upcomingMeetings',
            'scheduledMeetingsCount',
            'todayMeetingsCount',
            'monthlyData',
            'monthlyPayData',
            'categoryData',
            'workflowData',
            'delayedCount',
            'pipeline',
            'myTasks',
            'recentTransfers',
            'unassignedClientCount',
            'pendingRequests',
            'pendingRequestCount'
        ));
    }

    /**
     * Clients with a stage that's been Submitted/Need Revision for over a
     * week without action — the "something should have happened by now"
     * signal, independent of the sequential-lock mechanics.
     */
    private function delayedClientCount(): int
    {
        return ClientStageProgress::whereIn('client_id', Client::withoutTrashed()->whereIn('client_status', ['Running', 'Warning'])->pluck('id'))
            ->whereIn('status', [ClientStageProgress::STATUS_SUBMITTED, ClientStageProgress::STATUS_NEED_REVISION])
            ->where('updated_at', '<', now()->subDays(7))
            ->distinct('client_id')
            ->count('client_id');
    }

    /**
     * Aggregates the 19-step pipeline into the 8 department-facing segments
     * for the dashboard's workflow visualization: how many active clients are
     * currently in each segment, how many have stalled there, and what
     * percentage of the whole active client base has cleared it.
     */
    private function pipelineSegments(): array
    {
        $activeClientIds = Client::withoutTrashed()->whereIn('client_status', ['Running', 'Warning'])->pluck('id');
        $totalActive = $activeClientIds->count();

        $segments = [];
        foreach (self::PIPELINE_SEGMENTS as $label => $codes) {
            $stageIds = WorkflowStage::whereIn('code', $codes)->pluck('id');
            $stageCount = $stageIds->count();

            if ($stageCount === 0 || $totalActive === 0) {
                $segments[] = ['label' => $label, 'active' => 0, 'delayed' => 0, 'progress' => 0];
                continue;
            }

            $approvedCounts = ClientStageProgress::whereIn('client_id', $activeClientIds)
                ->whereIn('stage_id', $stageIds)
                ->where('status', ClientStageProgress::STATUS_APPROVED)
                ->selectRaw('client_id, COUNT(*) as cnt')
                ->groupBy('client_id')
                ->pluck('cnt', 'client_id');

            $completedClients = $approvedCounts->filter(fn($cnt) => $cnt >= $stageCount)->count();

            $activeInSegment = ClientStageProgress::whereIn('client_id', $activeClientIds)
                ->whereIn('stage_id', $stageIds)
                ->whereIn('status', [ClientStageProgress::STATUS_SUBMITTED, ClientStageProgress::STATUS_NEED_REVISION, ClientStageProgress::STATUS_IN_PROGRESS])
                ->distinct('client_id')
                ->count('client_id');

            $delayedInSegment = ClientStageProgress::whereIn('client_id', $activeClientIds)
                ->whereIn('stage_id', $stageIds)
                ->whereIn('status', [ClientStageProgress::STATUS_SUBMITTED, ClientStageProgress::STATUS_NEED_REVISION])
                ->where('updated_at', '<', now()->subDays(7))
                ->distinct('client_id')
                ->count('client_id');

            $segments[] = [
                'label' => $label,
                'active' => $activeInSegment,
                'delayed' => $delayedInSegment,
                'progress' => (int) round(($completedClients / $totalActive) * 100),
            ];
        }

        return $segments;
    }

    // ── Chart helpers ─────────────────────────────────────────────────────────

    private function monthlyClientData(): array
    {
        $rows = Client::selectRaw('MONTH(joining_date) as month, COUNT(*) as count')
            ->whereYear('joining_date', now()->year)
            ->whereNotNull('joining_date')
            ->withoutTrashed()
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month');

        $labels = collect(range(1, 12))->map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)));
        $data = collect(range(1, 12))->map(fn($m) => $rows->get($m, 0));

        return ['labels' => $labels->values()->all(), 'data' => $data->values()->all()];
    }

    private function monthlyPaymentData(): array
    {
        $rows = Payment::selectRaw('MONTH(payment_date) as month, SUM(amount) as total')
            ->where('status', 'Paid')
            ->whereYear('payment_date', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('total', 'month');

        $labels = collect(range(1, 12))->map(fn($m) => date('M', mktime(0, 0, 0, $m, 1)));
        $data = collect(range(1, 12))->map(fn($m) => (float) ($rows->get($m, 0)));

        return ['labels' => $labels->values()->all(), 'data' => $data->values()->all()];
    }

    private function categoryDistribution(): array
    {
        $rows = Client::withoutTrashed()
            ->selectRaw('category_id, COUNT(*) as count')
            ->with('category:id,name')
            ->groupBy('category_id')
            ->get();

        return [
            'labels' => $rows->map(fn($r) => $r->category?->name ?? 'Unknown')->all(),
            'data' => $rows->pluck('count')->all(),
        ];
    }

    private function workflowCompletionData(): array
    {
        $stages = WorkflowStage::where('status', true)->orderBy('sort_order')->get();

        $completedCounts = ClientStageProgress::where('is_completed', true)
            ->selectRaw('stage_id, COUNT(*) as cnt')
            ->groupBy('stage_id')
            ->pluck('cnt', 'stage_id');

        return [
            'labels' => $stages->pluck('name')->all(),
            'data' => $stages->map(fn($s) => $completedCounts->get($s->id, 0))->all(),
        ];
    }

    // ── Department-scoped dashboard ─────────────────────────────────
    private function departmentDashboard(User $user)
    {
        $departments = $user->getRoleNames()->intersect(self::DEPARTMENT_ROLES)->values();

        $pending = ClientStageProgress::with(['client:id,client_name,dfid_number,client_status', 'stage'])
            ->whereHas('stage', fn($q) => $q->whereIn('department', $departments)->where('status', true))
            ->whereIn('status', [
                ClientStageProgress::STATUS_PENDING,
                ClientStageProgress::STATUS_SUBMITTED,
                ClientStageProgress::STATUS_NEED_REVISION,
            ])
            ->get()
            ->filter(fn($progress) => !$this->workflowService->isLocked($progress->client_id, $progress->stage))
            ->values();

        $completedThisWeek = ClientStageProgress::whereHas('stage', fn($q) => $q->whereIn('department', $departments)->where('status', true))
            ->where('status', ClientStageProgress::STATUS_APPROVED)
            ->where('completed_at', '>=', now()->startOfWeek())
            ->count();

        $myTasks = Task::with('client:id,client_name,dfid_number')
            ->where('assigned_to', $user->id)
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->orderBy('due_date')
            ->limit(10)
            ->get();

        $overdueTaskCount = Task::where('assigned_to', $user->id)->overdue()->count();

        // ── My assigned clients (client-ownership feature) ────────────
        $myClientIds = Client::where('assigned_to', $user->id)->pluck('id');

        $myAssignedClientCount = Client::withoutTrashed()
            ->where('assigned_to', $user->id)
            ->count();

        $myActiveClientCount = Client::withoutTrashed()
            ->where('assigned_to', $user->id)
            ->whereIn('client_status', ['Running', 'Warning'])
            ->count();

        $followUpsDueToday = Task::whereDate('due_date', today())
            ->whereNotIn('status', ['Completed', 'Cancelled'])
            ->where(function ($q) use ($user, $myClientIds) {
                $q->where('assigned_to', $user->id)
                    ->orWhereIn('client_id', $myClientIds);
            })
            ->count();

        $recentlyAssignedClients = Client::withoutTrashed()
            ->where('assigned_to', $user->id)
            ->latest('updated_at')
            ->limit(5)
            ->get(['id', 'client_name', 'dfid_number', 'updated_at']);

        $recentlyTransferredToMe = ClientOwnershipTransfer::with('client:id,client_name,dfid_number')
            ->where('new_owner_id', $user->id)
            ->latest()
            ->limit(5)
            ->get();

        // ── Payment panel (Accounts / anyone with payment visibility) ──
        $paymentSummary = null;
        $recentPayments = null;

        if ($user->can('view payments')) {
            $paymentSummary = [
                'todayAmount' => Payment::whereDate('payment_date', today())->where('status', 'Paid')->sum('amount'),
                'thisMonthAmount' => Payment::where('status', 'Paid')
                    ->whereMonth('payment_date', now()->month)
                    ->whereYear('payment_date', now()->year)
                    ->sum('amount'),
                'pendingCount' => Payment::where('status', 'Unpaid')->count(),
                'pendingAmount' => Payment::where('status', 'Unpaid')->sum('amount'),
            ];

            $recentPayments = Payment::with('client:id,client_name,dfid_number')
                ->latest('payment_date')
                ->limit(6)
                ->get();
        }

        return view('dashboard-department', [
            'departments' => $departments,
            'pending' => $pending,
            'completedThisWeek' => $completedThisWeek,
            'myTasks' => $myTasks,
            'overdueTaskCount' => $overdueTaskCount,
            'myAssignedClientCount' => $myAssignedClientCount,
            'myActiveClientCount' => $myActiveClientCount,
            'followUpsDueToday' => $followUpsDueToday,
            'recentlyAssignedClients' => $recentlyAssignedClients,
            'recentlyTransferredToMe' => $recentlyTransferredToMe,
            'paymentSummary' => $paymentSummary,
            'recentPayments' => $recentPayments,
        ]);
    }
}
