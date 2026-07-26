<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\InteractsWithPortalUser;
use App\Models\ClientProjectUpdate;
use Illuminate\Http\Request;

class PortalUpdateController extends Controller
{
    use InteractsWithPortalUser;

    public function index(Request $request)
    {
        $client = $this->portalUser()->client;

        $query = ClientProjectUpdate::with('stage')
            ->where('client_id', $client->id)
            ->visible();

        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }
        if ($request->filled('stage_id')) {
            $query->where('stage_id', $request->stage_id);
        }
        if ($request->status === 'completed') {
            $query->where('progress_percent', 100);
        } elseif ($request->status === 'pending') {
            $query->where(fn ($q) => $q->whereNull('progress_percent')->orWhere('progress_percent', '<', 100));
        }

        $updates = $query->latest()->paginate(15)->withQueryString();

        $departments = ClientProjectUpdate::where('client_id', $client->id)
            ->visible()
            ->whereNotNull('department')
            ->distinct()
            ->pluck('department');

        return view('portal.updates.index', compact('updates', 'departments'));
    }

    public function show(ClientProjectUpdate $update)
    {
        abort_unless($update->client_id === $this->portalUser()->client_id && $update->is_client_visible, 404);

        return view('portal.updates.show', compact('update'));
    }
}
