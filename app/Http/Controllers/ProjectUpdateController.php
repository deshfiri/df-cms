<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientProjectUpdate;
use App\Services\ProjectUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectUpdateController extends Controller
{
    public function __construct(
        private readonly ProjectUpdateService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => $client->projectUpdates()->get()]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'title'                     => ['required', 'string', 'max:200'],
            'description'               => ['required', 'string', 'max:5000'],
            'stage_id'                  => ['nullable', 'exists:workflow_stages,id'],
            'department'                => ['nullable', 'string', 'max:60'],
            'progress_percent'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'next_action'               => ['nullable', 'string', 'max:2000'],
            'expected_completion_date'  => ['nullable', 'date'],
            'video_url'                 => ['nullable', 'url'],
            'external_link'             => ['nullable', 'url'],
            'is_client_visible'         => ['nullable', 'boolean'],
        ]);

        $update = $this->service->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $update]);
    }

    public function destroy(Client $client, ClientProjectUpdate $update): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($update->client_id !== $client->id, 404);

        $this->service->delete($update);

        return response()->json(['success' => true]);
    }
}
