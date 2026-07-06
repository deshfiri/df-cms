<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMeeting;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class MeetingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetingService,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $meetings = $client->meetings()
            ->with(['createdBy:id,name', 'assignedUser:id,name'])
            ->orderBy('scheduled_at', 'desc')
            ->get()
            ->map(fn ($m) => $this->resource($m));

        return response()->json(['data' => $meetings]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('manage-meetings');

        $data = $this->validateMeeting($request);

        if ($conflict = $this->meetingService->findConflict($client->id, $data['scheduled_at'], $data['duration_minutes'], assignedTo: $data['assigned_to'] ?? null)) {
            return $this->conflictResponse($conflict);
        }

        $meeting = $this->meetingService->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'meeting' => $this->resource($meeting)]);
    }

    public function availability(Request $request): JsonResponse
    {
        $this->authorize('manage-meetings');

        $data = $request->validate([
            'assigned_to'      => 'required|exists:users,id',
            'date'             => 'required|date',
            'duration_minutes' => 'required|integer|min:5|max:480',
        ]);

        $slots = $this->meetingService->availableSlots((int) $data['assigned_to'], $data['date'], (int) $data['duration_minutes']);

        return response()->json(['slots' => $slots]);
    }

    public function checkConflict(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'        => 'required|exists:clients,id',
            'scheduled_at'     => 'required|date',
            'duration_minutes' => 'required|integer|min:5',
            'exclude_id'       => 'nullable|integer',
            'assigned_to'      => 'nullable|exists:users,id',
        ]);

        $conflict = $this->meetingService->findConflict($data['client_id'], $data['scheduled_at'], $data['duration_minutes'], $data['exclude_id'] ?? null, $data['assigned_to'] ?? null);

        if ($conflict) {
            return response()->json([
                'conflict' => true,
                'title'    => $conflict->title,
                'time'     => $conflict->scheduled_at->format('d M Y, h:i A'),
                'duration' => $conflict->duration_human,
            ]);
        }

        return response()->json(['conflict' => false]);
    }

    public function bookForm(Request $request)
    {
        $this->authorize('manage-meetings');

        $scheduledCount      = ClientMeeting::whereIn('status', ClientMeeting::$openStatuses)->where('scheduled_at', '>=', now())->count();
        $preselectedClient   = $request->filled('client_id')
            ? Client::with('category:id,name')->find($request->integer('client_id'))
            : null;
        $users = \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('meetings.book', compact('scheduledCount', 'preselectedClient', 'users'));
    }

    public function bookStore(Request $request): JsonResponse
    {
        $this->authorize('manage-meetings');

        $data = $this->validateMeeting($request, requireClientId: true);

        if ($conflict = $this->meetingService->findConflict($data['client_id'], $data['scheduled_at'], $data['duration_minutes'], assignedTo: $data['assigned_to'] ?? null)) {
            return $this->conflictResponse($conflict);
        }

        $client  = Client::findOrFail($data['client_id']);
        $meeting = $this->meetingService->create($client, collect($data)->except('client_id')->all(), Auth::user());

        return response()->json([
            'success'      => true,
            'meeting'      => $this->resource($meeting),
            'client_url'   => route('clients.show', $client),
            'client_name'  => $client->client_name,
        ]);
    }

    public function update(Request $request, Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->authorize('manage-meetings');
        $this->checkBelongsToClient($client, $meeting);

        $data = $this->validateMeeting($request);

        if ($conflict = $this->meetingService->findConflict($client->id, $data['scheduled_at'], $data['duration_minutes'], excludeId: $meeting->id, assignedTo: $data['assigned_to'] ?? null)) {
            return $this->conflictResponse($conflict);
        }

        $updated = $this->meetingService->update($meeting, $data, Auth::user());

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function destroy(Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->authorize('manage-meetings');
        $this->checkBelongsToClient($client, $meeting);

        $this->meetingService->delete($meeting);

        return response()->json(['success' => true]);
    }

    public function complete(Request $request, Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->checkBelongsToClient($client, $meeting);
        $this->authorizeAssignedOrManage($meeting);

        $data = $request->validate(['notes' => 'nullable|string|max:5000']);
        $updated = $this->meetingService->complete($meeting, Auth::user(), $data['notes'] ?? null);

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function forceComplete(Request $request, Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->authorize('manage-workflow');
        $this->checkBelongsToClient($client, $meeting);

        $data = $request->validate(['notes' => 'nullable|string|max:5000']);
        $updated = $this->meetingService->forceComplete($meeting, Auth::user(), $data['notes'] ?? null);

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function cancel(Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->authorize('manage-meetings');
        $this->checkBelongsToClient($client, $meeting);

        $updated = $this->meetingService->cancel($meeting, Auth::user());

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function noShow(Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->checkBelongsToClient($client, $meeting);
        $this->authorizeAssignedOrManage($meeting);

        $updated = $this->meetingService->markNoShow($meeting, Auth::user());

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function regenerateLink(Client $client, ClientMeeting $meeting): JsonResponse
    {
        $this->authorize('manage-workflow');
        $this->checkBelongsToClient($client, $meeting);

        $updated = $this->meetingService->regenerateMeetLink($meeting, Auth::user());

        return response()->json(['success' => true, 'meeting' => $this->resource($updated)]);
    }

    public function allMeetings(Request $request)
    {
        if ($request->ajax()) {
            $query = ClientMeeting::with(['client:id,client_name,dfid_number', 'createdBy:id,name', 'assignedUser:id,name'])
                ->orderBy('scheduled_at', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('from')) {
                $query->whereDate('scheduled_at', '>=', $request->from);
            }
            if ($request->filled('to')) {
                $query->whereDate('scheduled_at', '<=', $request->to);
            }

            $meetings = $query->paginate(20)->through(fn ($m) => $this->resource($m));

            return response()->json($meetings);
        }

        $upcomingCount = ClientMeeting::upcoming()->count();
        $todayCount    = ClientMeeting::today()->count();
        $overdueCount  = ClientMeeting::overdue()->count();

        return view('meetings.index', compact('upcomingCount', 'todayCount', 'overdueCount'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function validateMeeting(Request $request, bool $requireClientId = false): array
    {
        return $request->validate([
            'client_id'        => $requireClientId ? 'required|exists:clients,id' : 'sometimes|exists:clients,id',
            'title'            => 'required|string|max:255',
            'agenda'           => 'nullable|string|max:5000',
            'type'             => ['required', Rule::in(ClientMeeting::$types)],
            'location'         => 'nullable|string|max:255',
            'meeting_link'     => 'nullable|url|max:500',
            'assigned_to'      => 'nullable|exists:users,id',
            'scheduled_at'     => 'required|date',
            'duration_minutes' => 'required|integer|min:5|max:480',
            'notes'            => 'nullable|string|max:5000',
        ]);
    }

    private function authorizeAssignedOrManage(ClientMeeting $meeting): void
    {
        $user = Auth::user();
        abort_unless(
            $user->id === $meeting->assigned_to || $user->can('manage-meetings'),
            403,
            'Only the assigned staff member (or someone with meeting management access) can do this.'
        );
    }

    private function conflictResponse(ClientMeeting $conflict): JsonResponse
    {
        return response()->json([
            'message' => 'Time slot conflict: "' . $conflict->title . '" is already scheduled at ' . $conflict->scheduled_at->format('d M Y, h:i A') . '.',
        ], 422);
    }

    private function checkBelongsToClient(Client $client, ClientMeeting $meeting): void
    {
        if ($meeting->client_id !== $client->id) {
            abort(404);
        }
    }

    private function resource(ClientMeeting $m): array
    {
        return [
            'id'               => $m->id,
            'title'            => $m->title,
            'agenda'           => $m->agenda,
            'type'             => $m->type,
            'type_label'       => $m->type_label,
            'type_icon'        => $m->type_icon,
            'location'         => $m->location,
            'meeting_link'     => $m->meeting_link,
            'google_meet_url'  => $m->google_meet_url,
            'join_url'         => $m->join_url,
            'scheduled_at'     => $m->scheduled_at?->format('Y-m-d H:i'),
            'scheduled_human'  => $m->scheduled_at?->format('d M Y, h:i A'),
            'scheduled_date'   => $m->scheduled_at?->format('d M Y'),
            'scheduled_time'   => $m->scheduled_at?->format('h:i A'),
            'duration_minutes' => $m->duration_minutes,
            'duration_human'   => $m->duration_human,
            'status'           => $m->status,
            'notes'            => $m->notes,
            'is_overdue'       => $m->is_overdue,
            'created_by_name'  => $m->createdBy?->name,
            'assigned_to'      => $m->assigned_to,
            'assigned_to_name' => $m->assignedUser?->name,
            'completed_at'     => $m->completed_at?->format('d M Y, h:i A'),
            'completed_by_name'=> $m->completedBy?->name,
            'client_name'      => $m->client?->client_name,
            'client_dfid'      => $m->client?->dfid_number,
            'client_id'        => $m->client_id,
            'created_at_human' => $m->created_at->diffForHumans(),
        ];
    }
}
