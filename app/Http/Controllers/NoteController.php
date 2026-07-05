<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNote;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NoteController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $notes = $client->notes()->with('user:id,name')->get();

        return response()->json($notes);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $request->validate(['note' => 'required|string|min:2']);

        $note = ClientNote::create([
            'client_id' => $client->id,
            'user_id'   => Auth::id(),
            'note'      => $request->note,
        ]);

        $this->activityLog->log('Note', 'Created', $client->id, null, ['note' => $request->note]);

        return response()->json(['success' => true, 'note' => $note->load('user:id,name')]);
    }

    public function destroy(Client $client, ClientNote $note): JsonResponse
    {
        if ($note->user_id !== Auth::id() && !Auth::user()->hasRole('Super Admin')) {
            abort(403, 'Cannot delete another user\'s note.');
        }
        $this->activityLog->log('Note', 'Deleted', $client->id, $note->toArray());
        $note->delete();

        return response()->json(['success' => true]);
    }
}
