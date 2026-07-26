<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $service,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('manage clients'), 403);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        return view('support-tickets.index');
    }

    public function show(Request $request, SupportTicket $ticket)
    {
        abort_unless($request->user()->can('manage clients'), 403);

        $ticket->load(['replies.author', 'replies.portalAuthor', 'client:id,client_name', 'createdBy:id,name']);

        return view('support-tickets.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($request->user()->can('manage clients'), 403);

        $data = $request->validate([
            'message'      => ['required', 'string', 'max:5000'],
            'is_internal_note' => ['nullable', 'boolean'],
        ]);

        $reply = $this->service->reply(
            $ticket,
            SupportTicketReply::AUTHOR_STAFF,
            Auth::user(),
            $data['message'],
            $request->file('file'),
            (bool) ($data['is_internal_note'] ?? false),
        );

        return response()->json(['success' => true, 'data' => $reply]);
    }

    public function assign(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($request->user()->can('manage clients'), 403);

        $data = $request->validate(['assigned_to' => ['required', 'exists:users,id']]);
        $ticket->update(['assigned_to' => $data['assigned_to'], 'status' => SupportTicket::STATUS_ASSIGNED]);

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    public function status(Request $request, SupportTicket $ticket): JsonResponse
    {
        abort_unless($request->user()->can('manage clients'), 403);

        $data = $request->validate(['status' => ['required', Rule::in(SupportTicket::$statuses)]]);
        $ticket->update([
            'status'    => $data['status'],
            'closed_at' => in_array($data['status'], ['Resolved', 'Closed'], true) ? now() : null,
        ]);

        return response()->json(['success' => true, 'data' => $ticket]);
    }

    private function dataTable(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['client:id,client_name', 'assignedTo:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return DataTables::of($query)
            ->addIndexColumn()
            ->addColumn('client', fn (SupportTicket $t) => e($t->client->client_name ?? '-'))
            ->addColumn('assigned', fn (SupportTicket $t) => e($t->assignedTo->name ?? 'Unassigned'))
            ->addColumn('created', fn (SupportTicket $t) => $t->created_at->format('d M Y'))
            ->make(true);
    }
}
