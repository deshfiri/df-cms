<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\ClientActionRequest;
use App\Policies\Portal\ActionRequestPolicy;
use App\Services\Portal\PortalActionRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PortalActionRequestController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly ActionRequestPolicy $policy,
        private readonly PortalActionRequestService $service,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $actionRequests = ClientActionRequest::where('client_id', $client->id)
            ->with('latestSubmission')
            ->latest()
            ->get();

        return view('portal.actions.index', compact('actionRequests'));
    }

    public function show(ClientActionRequest $actionRequest)
    {
        abort_unless($this->policy->view($this->portalUser(), $actionRequest), 404);

        $actionRequest->load('submissions');

        return view('portal.actions.show', compact('actionRequest'));
    }

    public function submit(Request $request, ClientActionRequest $actionRequest)
    {
        abort_unless($this->policy->submit($this->portalUser(), $actionRequest), 404);

        $data = $request->validate([
            'response_text' => ['nullable', 'string', 'max:5000'],
            'file'          => [$actionRequest->required_attachment ? 'required' : 'nullable', 'file', 'max:20480'],
        ]);

        if (empty($data['response_text']) && !$request->hasFile('file')) {
            throw ValidationException::withMessages([
                'response_text' => 'Please provide a response or attach a file.',
            ]);
        }

        $this->service->submit(
            $actionRequest,
            $this->portalUser(),
            $data['response_text'] ?? null,
            $request->file('file'),
        );

        return redirect()->route('portal.actions.show', $actionRequest)->with('success', 'Your response has been submitted.');
    }
}
