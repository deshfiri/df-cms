<?php

namespace App\Http\Controllers;

use App\Jobs\SyncBrandIntegration;
use App\Models\Brand;
use App\Models\BrandIntegration;
use App\Models\IntegrationResource;
use App\Models\SyncLog;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\MetaAuthService;
use App\Services\Meta\MetaResourceService;
use App\Services\PlatformSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Platform integrations for one brand: connect, choose resources, sync.
 *
 * Thin by design — OAuth lives in MetaAuthService, discovery in
 * MetaResourceService, syncing in PlatformSyncService. Every action authorises
 * against the brand first, so a user who can reach ads in general still cannot
 * reach a brand belonging to a client they cannot see.
 */
class BrandIntegrationController extends Controller
{
    /** Where the OAuth nonce is parked between redirect and callback. */
    private const STATE_SESSION_KEY = 'meta_oauth_state';

    public function __construct(
        private readonly MetaAuthService $auth,
        private readonly MetaResourceService $resources,
        private readonly PlatformSyncService $sync,
    ) {
    }

    /** Integration overview for a brand. */
    public function index(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $integrations = $brand->integrations()->with('resources')->get()
            ->map(fn(BrandIntegration $i) => $this->resource($i));

        return response()->json([
            'brand' => ['id' => $brand->id, 'name' => $brand->name, 'is_active' => $brand->is_active],
            'integrations' => $integrations,
            'meta_ready' => $this->auth->isConfigured(),
        ]);
    }

    /** Step 1: send the administrator to Meta. */
    public function connect(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('manage', $brand);

        if (!$this->auth->isConfigured()) {
            return back()->withErrors(['integration' => 'Meta is not configured. Set META_APP_ID and META_APP_SECRET.']);
        }

        $nonce = MetaAuthService::newNonce();
        $request->session()->put(self::STATE_SESSION_KEY, $nonce);

        return redirect()->away($this->auth->authorizationUrl($brand, $nonce));
    }

    /**
     * Step 2: Meta redirects back with a one-time code.
     *
     * The brand comes from the signed state rather than the URL, and the nonce
     * must match what this session sent — a callback replayed from elsewhere
     * cannot bolt an account onto someone else's brand.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = MetaAuthService::parseState($request->query('state'));
        $expectedNonce = $request->session()->pull(self::STATE_SESSION_KEY);

        if (!$state || !$expectedNonce || !hash_equals($expectedNonce, $state['nonce'])) {
            return redirect()->route('marketing.index')
                ->withErrors(['integration' => 'That Meta response could not be verified. Start the connection again.']);
        }

        $brand = Brand::findOrFail($state['brand_id']);
        $this->authorize('manage', $brand);

        if ($error = $request->query('error_description') ?? $request->query('error')) {
            return redirect()->route('marketing.brand', $brand)
                ->withErrors(['integration' => 'Meta refused the connection: ' . $error]);
        }

        if (!$code = $request->query('code')) {
            return redirect()->route('marketing.brand', $brand)
                ->withErrors(['integration' => 'Meta did not return an authorisation code.']);
        }

        try {
            $this->auth->completeConnection($brand, $code, $request->user());
        } catch (MetaApiException $e) {
            return redirect()->route('marketing.brand', $brand)
                ->withErrors(['integration' => $e->userMessage()]);
        }

        return redirect()->route('marketing.brand', $brand)
            ->with('success', 'Meta connected. Choose which resources belong to this brand.');
    }

    /** Step 3: what this Meta account can offer. */
    public function discover(BrandIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegration', $integration);

        try {
            $available = $this->resources->discover($integration);
        } catch (MetaApiException $e) {
            return response()->json(['success' => false, 'message' => $e->userMessage()], 422);
        }

        $selected = $integration->resources()->selected()->get()
            ->groupBy('type')
            ->map(fn($rows) => $rows->pluck('external_id')->all());

        return response()->json([
            'success' => true,
            'available' => $available,
            'selected' => $selected,
        ]);
    }

    /** Step 4: save which resources belong to the brand. */
    public function saveResources(Request $request, BrandIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegration', $integration);

        $data = $request->validate([
            'selection' => ['present', 'array'],
            'selection.*' => ['array'],
        ]);

        try {
            $available = $this->resources->discover($integration);
        } catch (MetaApiException $e) {
            return response()->json(['success' => false, 'message' => $e->userMessage()], 422);
        }

        $this->resources->storeSelection($integration, $available, $data['selection']);

        return response()->json([
            'success' => true,
            'resources' => $integration->fresh()->resources()->selected()->get(['type', 'external_id', 'name']),
        ]);
    }

    /** Queue a sync now, on top of the 20-minute schedule. */
    public function syncNow(Request $request, BrandIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegration', $integration);

        if (!$integration->isSyncable()) {
            return response()->json([
                'success' => false,
                'message' => $integration->tokenHasExpired()
                    ? 'The Meta connection has expired. Reconnect before syncing.'
                    : 'This integration is not connected.',
            ], 422);
        }

        // Refuse rather than queue a second run behind an in-flight one.
        if ($running = $this->sync->runningSyncFor($integration)) {
            return response()->json([
                'success' => false,
                'message' => 'A sync is already running for this brand. It started ' . $running->started_at->diffForHumans() . '.',
            ], 409);
        }

        SyncBrandIntegration::dispatch($integration->id, SyncLog::TYPE_MANUAL, $request->user()->id);

        return response()->json(['success' => true, 'message' => 'Sync queued.']);
    }

    public function disconnect(BrandIntegration $integration): JsonResponse
    {
        $this->authorize('manageIntegration', $integration);

        $this->auth->disconnect($integration);

        return response()->json(['success' => true]);
    }

    /** Recent sync history for a brand. */
    public function syncLogs(Brand $brand): JsonResponse
    {
        $this->authorize('view', $brand);

        $logs = $brand->syncLogs()->with('triggeredBy:id,name')->limit(20)->get()
            ->map(fn(SyncLog $log) => [
                'id' => $log->id,
                'platform' => $log->platform,
                'sync_type' => $log->sync_type,
                'status' => $log->status,
                'started_at' => $log->started_at->format('d M Y, h:i A'),
                'started_human' => $log->started_at->diffForHumans(),
                'duration_seconds' => $log->durationSeconds(),
                'records_processed' => $log->records_processed,
                'error_message' => $log->error_message,
                'triggered_by' => $log->triggeredBy?->name,
            ]);

        return response()->json(['logs' => $logs]);
    }

    /**
     * Public shape of an integration. Note what is absent: credentials never
     * appear here, and the model hides them from serialisation anyway.
     */
    private function resource(BrandIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'platform' => $integration->platform,
            'status' => $integration->status,
            'account_name' => $integration->metadata['account_name'] ?? null,
            'connected_at' => $integration->connected_at?->format('d M Y, h:i A'),
            'last_synced_at' => $integration->last_synced_at?->format('d M Y, h:i A'),
            'last_synced_human' => $integration->last_synced_at?->diffForHumans(),
            'next_sync_at' => $integration->nextSyncAt()?->format('d M Y, h:i A'),
            'token_expired' => $integration->tokenHasExpired(),
            'last_error' => $integration->last_error,
            'resource_counts' => $integration->resources
                ->where('is_selected', true)
                ->groupBy('type')
                ->map->count(),
        ];
    }
}
