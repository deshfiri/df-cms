<?php

namespace App\Http\Controllers;

use App\Services\Storage\StorageProbe;
use App\Services\Storage\StorageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Settings → Storage & CDN.
 *
 * Where uploads go is a single installation-wide choice. Selecting a provider
 * whose credentials do not work would silently break every upload, so a
 * provider can only be made active once it has been proved with a real round
 * trip — see StorageProbe.
 */
class StorageSettingsController extends Controller
{
    public function __construct(
        private readonly StorageSettings $settings,
        private readonly StorageProbe $probe,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403);

            return $next($request);
        });
    }

    public function index()
    {
        return view('settings.storage', [
            'provider'           => $this->settings->provider(),
            'activeDisk'         => $this->settings->activeDisk(),
            'r2'                 => $this->settings->r2Config(),
            'cloudinary'         => $this->settings->cloudinaryConfig(),
            'r2Configured'       => $this->settings->isConfigured(StorageSettings::PROVIDER_CLOUDFLARE),
            'cloudinaryConfigured' => $this->settings->isConfigured(StorageSettings::PROVIDER_CLOUDINARY),
            'hasR2Secret'        => $this->settings->hasSecret(StorageSettings::KEY_R2_SECRET),
            'hasCloudinarySecret' => $this->settings->hasSecret(StorageSettings::KEY_CLOUDINARY_SECRET),
            'usage'              => $this->usage(),
        ]);
    }

    /** Save one provider's credentials without changing which one is active. */
    public function update(Request $request): RedirectResponse
    {
        $provider = $this->validatedProvider($request);

        if ($provider === StorageSettings::PROVIDER_CLOUDFLARE) {
            $data = $request->validate([
                'account_id' => ['required', 'string', 'max:100'],
                'access_key' => ['required', 'string', 'max:200'],
                // Optional on re-save: blank keeps the stored secret, which is
                // never rendered back into the form.
                'secret'     => ['nullable', 'string', 'max:400'],
                'bucket'     => ['required', 'string', 'max:120'],
                'url'        => ['nullable', 'string', 'max:255'],
            ]);

            $this->settings->putR2($data);
        } else {
            $data = $request->validate([
                'cloud_name' => ['required', 'string', 'max:120'],
                'api_key'    => ['required', 'string', 'max:120'],
                'api_secret' => ['nullable', 'string', 'max:400'],
                'folder'     => ['nullable', 'string', 'max:120'],
            ]);

            $this->settings->putCloudinary($data);
        }

        // Which panel to reopen. Without it the page comes back showing whatever
        // provider is *active*, so an admin who just saved Cloudinary is looking
        // at the local panel — with the Activate button they still need, and any
        // validation errors, both hidden from them.
        return back()
            ->with('panel', $provider)
            ->with('success', $this->label($provider) . ' credentials saved. Now test the connection, then activate it — saving alone does not move any uploads.');
    }

    /**
     * Make a provider the destination for new uploads.
     *
     * Guarded twice: the credentials must be complete, and the round trip must
     * actually succeed. Switching back to self-hosted is never blocked — that
     * is the escape hatch when a provider goes wrong.
     */
    public function activate(Request $request): RedirectResponse
    {
        $provider = $this->validatedProvider($request, allowLocal: true);

        if ($provider === StorageSettings::PROVIDER_LOCAL) {
            $this->settings->putProvider($provider);

            return back()->with('success', 'New uploads will be stored on this server again. Files already on a CDN stay there and keep working.');
        }

        if (!$this->settings->isConfigured($provider)) {
            return back()
                ->with('panel', $provider)
                ->withErrors(['storage' => 'Enter and save the credentials for that provider first.']);
        }

        $result = $this->probe->run(StorageSettings::DISKS[$provider]);

        if (!$result['ok']) {
            return back()
                ->with('panel', $provider)
                ->withErrors(['storage' => 'Not activated — the connection test failed. ' . $result['message']]);
        }

        $this->settings->putProvider($provider);

        return back()
            ->with('panel', $provider)
            ->with('success', 'Connected. New uploads now go to ' . $this->label($provider) . '.');
    }

    /** Round-trip test, run from the UI without changing anything. */
    public function test(Request $request): JsonResponse
    {
        $provider = $this->validatedProvider($request);

        if (!$this->settings->isConfigured($provider)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Save the credentials for that provider first.',
                'steps'   => [],
            ]);
        }

        return response()->json($this->probe->run(StorageSettings::DISKS[$provider]));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $provider = $this->validatedProvider($request);

        $this->settings->disconnect($provider);

        return back()
            ->with('panel', $provider)
            ->with('success', $this->label($provider) . ' disconnected. Files already stored there keep working — only new uploads move.');
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function validatedProvider(Request $request, bool $allowLocal = false): string
    {
        $allowed = $allowLocal
            ? array_keys(StorageSettings::DISKS)
            : [StorageSettings::PROVIDER_CLOUDFLARE, StorageSettings::PROVIDER_CLOUDINARY];

        return $request->validate([
            'provider' => ['required', Rule::in($allowed)],
        ])['provider'];
    }

    private function label(string $provider): string
    {
        return match ($provider) {
            StorageSettings::PROVIDER_CLOUDFLARE => 'Cloudflare R2',
            StorageSettings::PROVIDER_CLOUDINARY => 'Cloudinary',
            default => 'this server',
        };
    }

    /**
     * How many stored files sit on each disk.
     *
     * The reason this is on the page: it makes "old files stay where they were"
     * visible instead of a claim, so an admin can see nothing was stranded.
     *
     * @return array<string,int>
     */
    private function usage(): array
    {
        $sources = [
            ['table' => 'client_documents', 'column' => 'disk'],
            ['table' => 'documents', 'column' => 'disk'],
            ['table' => 'task_attachments', 'column' => 'disk'],
            ['table' => 'flow_item_attachments', 'column' => 'disk'],
            ['table' => 'messages', 'column' => 'attachment_disk'],
            ['table' => 'payment_proof_submissions', 'column' => 'disk'],
            ['table' => 'client_approval_requests', 'column' => 'disk'],
            ['table' => 'client_approval_responses', 'column' => 'disk'],
            ['table' => 'client_action_submissions', 'column' => 'disk'],
            ['table' => 'client_correction_requests', 'column' => 'disk'],
            ['table' => 'support_tickets', 'column' => 'disk'],
            ['table' => 'support_ticket_replies', 'column' => 'disk'],
            ['table' => 'client_project_updates', 'column' => 'disk'],
        ];

        $totals = [];

        foreach ($sources as ['table' => $table, 'column' => $column]) {
            // A module may not be migrated in on every install.
            if (!Schema::hasColumn($table, $column)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull($column)
                ->selectRaw("{$column} as disk, COUNT(*) as total")
                ->groupBy($column)
                ->pluck('total', 'disk');

            foreach ($rows as $disk => $total) {
                $totals[$disk] = ($totals[$disk] ?? 0) + (int) $total;
            }
        }

        arsort($totals);

        return $totals;
    }
}
