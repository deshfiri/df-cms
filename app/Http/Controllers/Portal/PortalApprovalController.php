<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\ClientApprovalRequest;
use App\Policies\Portal\ApprovalRequestPolicy;
use App\Services\Portal\PortalApprovalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class PortalApprovalController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly ApprovalRequestPolicy $policy,
        private readonly PortalApprovalService $service,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $approvalRequests = ClientApprovalRequest::where('client_id', $client->id)->latest()->get();

        return view('portal.approvals.index', compact('approvalRequests'));
    }

    public function show(ClientApprovalRequest $approvalRequest)
    {
        abort_unless($this->policy->view($this->portalUser(), $approvalRequest), 404);

        $approvalRequest->load('responses.respondedBy');

        return view('portal.approvals.show', compact('approvalRequest'));
    }

    public function respond(Request $request, ClientApprovalRequest $approvalRequest)
    {
        abort_unless($this->policy->respond($this->portalUser(), $approvalRequest), 404);

        $data = $request->validate([
            'response' => ['required', Rule::in(['Approved', 'Revision Requested', 'Rejected'])],
            'comment'  => ['nullable', 'string', 'max:2000'],
            'file'     => ['nullable', 'file', 'max:20480'],
        ]);

        if ($data['response'] === 'Rejected' && !$this->policy->reject($this->portalUser(), $approvalRequest)) {
            throw ValidationException::withMessages(['response' => 'Rejection is not allowed for this approval.']);
        }

        $this->service->respond(
            $approvalRequest,
            $this->portalUser(),
            $data['response'],
            $data['comment'] ?? null,
            $request->file('file'),
        );

        return redirect()->route('portal.approvals.show', $approvalRequest)->with('success', 'Your response has been recorded.');
    }
}
