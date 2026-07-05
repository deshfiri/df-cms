<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\PendingChange;
use App\Models\Payment;
use App\Models\Task;
use App\Models\User;
use App\Services\CategoryService;
use App\Services\ClientService;
use App\Services\MeetingService;
use App\Services\PaymentService;
use App\Services\TaskService;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

class PendingChangeController extends Controller
{
    public function __construct(
        private readonly ClientService   $clientService,
        private readonly PaymentService  $paymentService,
        private readonly TaskService     $taskService,
        private readonly CategoryService $categoryService,
        private readonly UserService     $userService,
        private readonly MeetingService  $meetingService,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole(['Super Admin', 'Manager']), 403);
            return $next($request);
        });
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            $changes = PendingChange::with(['requestedBy:id,name'])
                ->pending()
                ->latest()
                ->get()
                ->map(fn (PendingChange $p) => $this->resource($p));

            return response()->json(['data' => $changes]);
        }

        return view('pending-changes.index');
    }

    public function approve(PendingChange $pendingChange): JsonResponse
    {
        if ($pendingChange->status !== PendingChange::STATUS_PENDING) {
            return response()->json(['message' => 'This change has already been reviewed.'], 422);
        }

        $model = $this->resolveModel($pendingChange);
        if (!$model) {
            $pendingChange->update([
                'status'       => PendingChange::STATUS_REJECTED,
                'reviewed_by'  => Auth::id(),
                'reviewed_at'  => now(),
                'review_note'  => 'Underlying record no longer exists.',
            ]);

            return response()->json(['message' => 'The original record no longer exists; this change has been auto-rejected.'], 422);
        }

        try {
            match ($pendingChange->model_type) {
                Client::class        => $this->clientService->update($model, $pendingChange->new_values),
                Payment::class       => $this->paymentService->update($model, $pendingChange->new_values),
                Task::class          => $this->taskService->update($model, $pendingChange->new_values),
                Category::class      => $this->categoryService->update($model, $pendingChange->new_values),
                User::class          => $this->userService->update($model, $pendingChange->new_values),
                ClientMeeting::class => $this->meetingService->update($model, $pendingChange->new_values, Auth::user()),
                default              => throw new RuntimeException('Unsupported change type.'),
            };
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not apply change: ' . $e->getMessage()], 422);
        }

        $pendingChange->update([
            'status'      => PendingChange::STATUS_APPROVED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function reject(Request $request, PendingChange $pendingChange): JsonResponse
    {
        if ($pendingChange->status !== PendingChange::STATUS_PENDING) {
            return response()->json(['message' => 'This change has already been reviewed.'], 422);
        }

        $data = $request->validate(['note' => 'nullable|string|max:1000']);

        $pendingChange->update([
            'status'      => PendingChange::STATUS_REJECTED,
            'reviewed_by' => Auth::id(),
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    private function resolveModel(PendingChange $pendingChange): ?Model
    {
        $modelClass = $pendingChange->model_type;
        if (!class_exists($modelClass)) {
            return null;
        }

        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query->find($pendingChange->model_id);
    }

    private function resource(PendingChange $p): array
    {
        return [
            'id'               => $p->id,
            'model_label'      => class_basename($p->model_type),
            'model_id'         => $p->model_id,
            'old_values'       => $p->old_values,
            'new_values'       => $p->new_values,
            'requested_by'     => $p->requestedBy?->name,
            'created_at_human' => $p->created_at->diffForHumans(),
            'created_at'       => $p->created_at->format('d M Y, h:i A'),
        ];
    }
}
