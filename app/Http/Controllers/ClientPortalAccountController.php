<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientPortalUser;
use App\Services\ClientPortalAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientPortalAccountController extends Controller
{
    public function __construct(
        private readonly ClientPortalAccountService $service,
    ) {}

    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        return response()->json(['data' => $client->portalUsers()->get()]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['nullable', 'email', 'unique:client_portal_users,email'],
            'phone'       => ['nullable', 'string', 'unique:client_portal_users,phone'],
            'password'    => ['required', 'confirmed', Password::defaults()],
            'is_primary'  => ['nullable', 'boolean'],
        ]);

        abort_if(empty($data['email']) && empty($data['phone']), 422, 'Provide an email or phone for the login.');

        $portalUser = $this->service->create($client, $data, Auth::user());

        return response()->json(['success' => true, 'data' => $portalUser]);
    }

    public function status(Request $request, Client $client, ClientPortalUser $portalAccount): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($portalAccount->client_id !== $client->id, 404);

        $data = $request->validate(['status' => ['required', Rule::in(ClientPortalUser::$statuses)]]);
        $updated = $this->service->updateStatus($portalAccount, $data['status']);

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function resetPassword(Request $request, Client $client, ClientPortalUser $portalAccount): JsonResponse
    {
        $this->authorize('update', $client);
        abort_if($portalAccount->client_id !== $client->id, 404);

        $data = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        $this->service->resetPassword($portalAccount, $data['password']);

        return response()->json(['success' => true]);
    }
}
