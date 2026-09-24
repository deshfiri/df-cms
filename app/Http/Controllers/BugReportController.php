<?php

namespace App\Http\Controllers;

use App\Http\Requests\BugReport\StoreBugReportRequest;
use App\Models\BugReport;
use App\Services\BugReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class BugReportController extends Controller
{
    public function __construct(
        private readonly BugReportService $service,
    ) {
    }

    public function index(Request $request)
    {
        // The page and its table feed alike: nobody sees a list they can't
        // actually have rows in — the datatable is scoped below.
        $this->authorize('viewAny', BugReport::class);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $canManage = $request->user()->can('manage bug reports');
        $canCreate = $request->user()->can('create', BugReport::class);

        return view('bug-reports.index', compact('canManage', 'canCreate'));
    }

    public function store(StoreBugReportRequest $request): JsonResponse
    {
        $report = $this->service->create($request->validated(), $request->user());

        return response()->json(['success' => true, 'report' => $report]);
    }

    public function respond(Request $request, BugReport $bugReport): JsonResponse
    {
        $this->authorize('respond', $bugReport);

        if ($bugReport->status !== BugReport::STATUS_OPEN) {
            return response()->json(['message' => 'This report has already been reviewed.'], 422);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([BugReport::STATUS_RESOLVED, BugReport::STATUS_CLOSED])],
            'note'   => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->respond($bugReport, $data['status'], $data['note'] ?? null, $request->user());

        return response()->json(['success' => true, 'report' => $updated]);
    }

    public function destroy(BugReport $bugReport): JsonResponse
    {
        $this->authorize('delete', $bugReport);
        $this->service->delete($bugReport);

        return response()->json(['success' => true]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $user      = $request->user();
        $canManage = $user->can('manage bug reports');

        $query = BugReport::query()->with('reportedBy:id,name');

        if (!$canManage) {
            $query->where('reported_by', $user->id);
        } elseif ($request->boolean('mine_only')) {
            $query->where('reported_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('subject', fn (BugReport $r) => e($r->subject))
            ->addColumn('reporter', fn (BugReport $r) => e($r->reportedBy->name ?? '-'))
            ->addColumn('severity_badge', fn (BugReport $r) => $this->severityBadge($r->severity))
            ->addColumn('status_badge', fn (BugReport $r) => $this->statusBadge($r->status))
            ->addColumn('created', fn (BugReport $r) => $r->created_at->format('d M Y'))
            ->addColumn('actions', fn (BugReport $r) => $this->actionButtons($r, $user, $canManage))
            ->rawColumns(['severity_badge', 'status_badge', 'actions'])
            ->make(true);
    }

    private function severityBadge(string $severity): string
    {
        $map = ['Low' => 'spill-hold', 'Medium' => 'spill-pending', 'High' => 'spill-warning', 'Critical' => 'spill-rejected'];

        return '<span class="spill ' . ($map[$severity] ?? 'spill-hold') . '">' . e($severity) . '</span>';
    }

    private function statusBadge(string $status): string
    {
        $map = [
            BugReport::STATUS_OPEN     => 'spill-pending',
            BugReport::STATUS_RESOLVED => 'spill-approved',
            BugReport::STATUS_CLOSED   => 'spill-rejected',
        ];

        return '<span class="spill ' . ($map[$status] ?? 'spill-pending') . '">' . e($status) . '</span>';
    }

    private function actionButtons(BugReport $r, $user, bool $canManage): string
    {
        $html = '<button class="btn btn-sm px-2 py-1 bug-view" data-id="' . $r->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="View"><i class="bi bi-eye"></i></button> ';

        if ($canManage && $r->status === BugReport::STATUS_OPEN) {
            $html .= '<button class="btn btn-sm px-2 py-1 bug-resolve" data-id="' . $r->id . '" style="background:var(--c-green-bg);border:1px solid var(--c-green-bg);color:var(--c-green)" title="Resolve"><i class="bi bi-check-lg"></i></button> '
                . '<button class="btn btn-sm px-2 py-1 bug-close" data-id="' . $r->id . '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg);color:var(--c-red)" title="Close (not a bug / won\'t fix)"><i class="bi bi-x-lg"></i></button> ';
        }

        if ($r->status === BugReport::STATUS_OPEN && ($r->reported_by === $user->id || $canManage)) {
            $html .= '<button class="btn btn-sm px-2 py-1 bug-delete" data-id="' . $r->id . '" style="background:var(--c-red-bg);border:1px solid var(--c-red-bg);color:var(--c-red)" title="Delete"><i class="bi bi-trash"></i></button>';
        }

        return $html;
    }
}
