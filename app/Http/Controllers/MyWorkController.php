<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\MyWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Everyone's own workload and output, for the My Work panel on the dashboard.
 *
 * Only ever about the signed-in person, so there is nothing to authorize
 * beyond being signed in.
 */
class MyWorkController extends Controller
{
    public function __construct(
        private readonly MyWorkService $myWork,
    ) {}

    /** Every signed-in user's own page: the panel, plus the work itself. */
    public function index(Request $request): View
    {
        $me      = $request->user();
        $columns = ['id', 'title', 'client_id', 'assigned_to', 'created_by', 'status', 'priority', 'due_date', 'due_at', 'submitted_at', 'completed_at'];

        return view('my-work.index', [
            'openTasks' => Task::with('client:id,client_name')
                ->where('assigned_to', $me->id)
                ->whereNotIn('status', Task::$settledStatuses)
                ->orderByRaw('due_at IS NULL')->orderBy('due_at')
                ->limit(15)
                ->get($columns),
            'waitingOnMe' => Task::with(['client:id,client_name', 'assignedUser:id,name'])
                ->where('created_by', $me->id)
                ->where('assigned_to', '!=', $me->id)
                ->where('status', Task::STATUS_SUBMITTED)
                ->latest('submitted_at')
                ->limit(15)
                ->get($columns),
            'handedIn' => Task::with('client:id,client_name')
                ->where('assigned_to', $me->id)
                ->where('status', Task::STATUS_SUBMITTED)
                ->latest('submitted_at')
                ->limit(15)
                ->get($columns),
            'canTasks' => $me->canAny(['view tasks', 'manage tasks']),
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $request->validate(['tz' => ['nullable', 'string', 'max:64']]);

        return response()->json(
            $this->myWork->summary($request->user(), MyWorkService::timezone($request->query('tz')))
        );
    }
}
