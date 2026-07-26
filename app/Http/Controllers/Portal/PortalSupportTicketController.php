<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Policies\Portal\SupportTicketPolicy;
use App\Services\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalSupportTicketController extends Controller
{
    use InteractsWithPortalUser;

    public function __construct(
        private readonly SupportTicketPolicy $policy,
        private readonly SupportTicketService $service,
    ) {}

    public function index()
    {
        $client = $this->portalUser()->client;

        $tickets = SupportTicket::where('client_id', $client->id)->latest()->get();

        return view('portal.support.index', compact('tickets'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(SupportTicket::$categories)],
            'priority' => ['required', Rule::in(SupportTicket::$priorities)],
            'subject'  => ['required', 'string', 'max:200'],
            'message'  => ['required', 'string', 'max:5000'],
            'file'     => ['nullable', 'file', 'max:20480'],
        ]);

        $ticket = $this->service->create(
            $this->portalUser()->client,
            $this->portalUser(),
            $data['category'],
            $data['priority'],
            $data['subject'],
            $data['message'],
            $request->file('file'),
        );

        return redirect()->route('portal.support.show', $ticket)->with('success', 'Support ticket created.');
    }

    public function show(SupportTicket $ticket)
    {
        abort_unless($this->policy->view($this->portalUser(), $ticket), 404);

        $ticket->load(['replies' => fn ($q) => $q->clientVisible()->with(['author', 'portalAuthor'])]);

        return view('portal.support.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket)
    {
        abort_unless($this->policy->reply($this->portalUser(), $ticket), 404);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'file'    => ['nullable', 'file', 'max:20480'],
        ]);

        $this->service->reply(
            $ticket,
            SupportTicketReply::AUTHOR_PORTAL,
            $this->portalUser(),
            $data['message'],
            $request->file('file'),
        );

        return redirect()->route('portal.support.show', $ticket)->with('success', 'Reply sent.');
    }
}
