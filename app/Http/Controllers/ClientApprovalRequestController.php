<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientApprovalRequest;
use App\Services\ClientApprovalRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ClientApprovalRequestController extends Controller
{
    public function __construct(
        private readonly ClientApprovalRequestService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $approvalRequests = $client->approvalRequests()->with('responses')->get();

        return response()->json(['data' => $approvalRequests]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'approval_type'         => ['required', Rule::in(\App\Models\ClientApprovalRequest::$types)],
            'title'                 => ['required', 'string', 'max:200'],
            'description'           => ['nullable', 'string', 'max:5000'],
            'external_preview_url'  => ['nullable', 'url'],
            'deadline'              => ['nullable', 'date'],
            'allow_reject'          => ['nullable', 'boolean'],
            'stage_id'              => ['nullable', 'exists:workflow_stages,id'],
            'file'                  => ['nullable', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        unset($data['file']);

        $approvalRequest = $this->service->create($client, $data, $file, Auth::user());

        return response()->json(['success' => true, 'data' => $approvalRequest]);
    }

    public function show(Client $client, ClientApprovalRequest $approvalRequest): JsonResponse
    {
        $this->authorize('view', $client);
        abort_if($approvalRequest->client_id !== $client->id, 404);

        return response()->json(['data' => $approvalRequest->load('responses.respondedBy')]);
    }
}
