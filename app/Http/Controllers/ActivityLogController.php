<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

/**
 * Company-wide monitoring of every module's own audit trail (Client, Task,
 * Request, Payment, Workflow, ...) — the same ActivityLog rows each module
 * already writes via ActivityLogService, just no longer scattered across
 * per-client tabs. Gated by 'view activity log'; Super Admin sees it via the
 * usual Gate::before bypass regardless of role.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Auth::user()->can('view activity log'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $modules = ActivityLog::query()->select('module')->distinct()->orderBy('module')->pluck('module');
        $users = User::orderBy('name')->get(['id', 'name']);

        return view('activity-log.index', compact('modules', 'users'));
    }

    public function show(ActivityLog $activityLog): JsonResponse
    {
        abort_unless(Auth::user()->can('view activity log'), 403);

        $activityLog->load(['user:id,name', 'client:id,client_name']);

        return response()->json([
            'module'      => $activityLog->module,
            'action'      => $activityLog->action,
            'user'        => $activityLog->user->name ?? 'System',
            'client'      => $activityLog->client->client_name ?? null,
            'created_at'  => $activityLog->created_at->format('d M Y, h:i A'),
            'ip_address'  => $activityLog->ip_address,
            'browser'     => $activityLog->browser,
            'old_value'   => $activityLog->old_value,
            'new_value'   => $activityLog->new_value,
        ]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = ActivityLog::query()->with(['user:id,name', 'client:id,client_name']);

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }
        if ($request->filled('action')) {
            $query->where('action', 'LIKE', '%' . $request->action . '%');
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('module_badge', fn (ActivityLog $log) => '<span class="spill" style="background:var(--surface2);color:var(--text2);border:1px solid var(--border)">' . e($log->module) . '</span>')
            ->addColumn('action', fn (ActivityLog $log) => e($log->action))
            ->addColumn('user', fn (ActivityLog $log) => e($log->user->name ?? 'System'))
            ->addColumn('client', fn (ActivityLog $log) => e($log->client->client_name ?? '-'))
            ->addColumn('when', fn (ActivityLog $log) => $log->created_at->format('d M Y, h:i A'))
            ->addColumn('ip', fn (ActivityLog $log) => e($log->ip_address ?? '-'))
            ->addColumn('details', fn (ActivityLog $log) => '<button class="btn btn-sm px-2 py-1 log-view" data-id="' . $log->id . '" style="background:var(--surface2);border:1px solid var(--border);color:var(--text2)" title="Details"><i class="bi bi-eye"></i></button>')
            ->orderColumn('when', 'created_at $1')
            ->rawColumns(['module_badge', 'details'])
            ->make(true);
    }
}
