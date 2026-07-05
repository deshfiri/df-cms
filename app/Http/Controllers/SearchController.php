<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function global(Request $request): JsonResponse
    {
        $q = trim($request->input('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%' . addcslashes($q, '%_\\') . '%';

        // Full-text if long enough (≥3 chars), otherwise LIKE fallback
        if (strlen($q) >= 3) {
            $clientIds = Client::withoutTrashed()
                ->whereRaw('MATCH(client_name, brand_name, dfid_number, website, remarks) AGAINST(? IN BOOLEAN MODE)', ['+' . $q . '*'])
                ->limit(10)
                ->pluck('id');

            // Also search notes via full-text and merge client IDs
            $noteClientIds = ClientNote::whereRaw('MATCH(note) AGAINST(? IN BOOLEAN MODE)', ['+' . $q . '*'])
                ->limit(5)
                ->pluck('client_id');

            $allIds = $clientIds->merge($noteClientIds)->unique()->take(10)->values();
        } else {
            $allIds = Client::withoutTrashed()
                ->where(function ($sq) use ($like) {
                    $sq->where('dfid_number', 'like', $like)
                       ->orWhere('client_name', 'like', $like)
                       ->orWhere('brand_name', 'like', $like);
                })
                ->limit(10)
                ->pluck('id');
        }

        $clients = Client::withoutTrashed()
            ->with('category:id,name')
            ->whereIn('id', $allIds)
            ->get(['id', 'dfid_number', 'client_name', 'brand_name', 'client_status', 'category_id'])
            ->map(fn ($c) => [
                'id'            => $c->id,
                'dfid_number'   => $c->dfid_number,
                'client_name'   => $c->client_name,
                'brand_name'    => $c->brand_name,
                'client_status' => $c->client_status,
                'category'      => $c->category?->name,
                'url'           => route('clients.show', $c),
            ]);

        return response()->json($clients);
    }
}
