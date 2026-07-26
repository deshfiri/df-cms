<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientActionRequest;
use App\Services\ClientActionRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClientActionRequestController extends Controller
{
    public function __construct(
        private readonly ClientActionRequestService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $actionRequests = $client->actionRequests()->with('reviewedBy', 'latestSubmission')->get();

        return response()->json(['data' => $actionRequests]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'title'               => ['required', 'string', 'max:200'],
            'description'         => ['required', 'string', 'max:5000'],
            'required_attachment' => ['nullable', 'boolean'],
            'priority'            => ['required', Rule::in(ClientActionRequest::$priorities)],
            'due_date'            => ['nullable', 'date'],
            'stage_id'            => ['nullable', 'exists:workflow_stages,id'],
        ]);

        $actionRequest = $this->service->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $actionRequest]);
    }

    public function review(Request $request, Client $client, ClientActionRequest $actionRequest): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($actionRequest->client_id !== $client->id, 404);

        $data = $request->validate([
            'status'   => ['required', Rule::in(['Approved', 'Need Revision', 'Rejected', 'Completed'])],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $updated = $this->service->review($actionRequest, $data['status'], $data['feedback'] ?? null, Auth::user());

        return response()->json(['success' => true, 'data' => $updated]);
    }
}
