<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\WhatsApp\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → WhatsApp: the Meta *app* credentials for the whole installation.
 *
 * Nothing here ever renders a secret back to the browser. The form shows whether
 * a secret is on record, and a blank field on submit means "keep it" — the same
 * contract the Meta Marketing, Google and Storage settings screens already use.
 */
class WhatsAppSettingsController extends Controller
{
    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly ActivityLogService $activityLog,
    ) {
        $this->middleware(function ($request, $next) {
            // Credentials are Super Admin territory, like every other
            // integration secret in this application.
            abort_unless(
                $request->user()->hasRole('Super Admin') || $request->user()->can('manage whatsapp settings'),
                403,
            );

            return $next($request);
        });
    }

    public function index()
    {
        return view('whatsapp.settings', [
            'appId'         => $this->settings->appId(),
            'configId'      => $this->settings->configId(),
            'apiVersion'    => $this->settings->apiVersion(),
            'webhookUrl'    => $this->settings->webhookUrl(),
            'hasAppSecret'  => $this->settings->hasSecret(WhatsAppSettings::KEY_APP_SECRET),
            'hasVerifyToken'=> $this->settings->hasSecret(WhatsAppSettings::KEY_VERIFY_TOKEN),
            'isConfigured'  => $this->settings->isConfigured(),
            'canOnboard'    => $this->settings->canOnboard(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id'       => ['required', 'string', 'max:64'],
            // Optional on re-save: blank keeps the stored secret.
            'app_secret'   => ['nullable', 'string', 'max:255'],
            'config_id'    => ['nullable', 'string', 'max:64'],
            'verify_token' => ['nullable', 'string', 'min:12', 'max:255'],
            'api_version'  => ['nullable', 'string', 'regex:/^v\d+\.\d+$/'],
        ], [
            'api_version.regex'  => 'The API version should look like v21.0.',
            'verify_token.min'   => 'The verify token should be at least 12 characters.',
        ]);

        $this->settings->putCredentials($data['app_id'], $data['app_secret'] ?? null, $data['config_id'] ?? null);
        $this->settings->putVerifyToken($data['verify_token'] ?? null);
        $this->settings->putApiVersion($data['api_version'] ?? null);

        // Records that credentials changed, never what they changed to.
        $this->activityLog->log(
            module: 'WhatsApp',
            action: 'Settings Updated',
            clientId: null,
            newValue: ['app_id_set' => filled($data['app_id']), 'secret_rotated' => filled($data['app_secret'] ?? null)],
        );

        return back()->with('success', 'WhatsApp settings saved.');
    }

    /** Generate a webhook verify token so nobody has to invent one. */
    public function regenerateVerifyToken(): JsonResponse
    {
        $token = $this->settings->generateVerifyToken();

        $this->activityLog->log(module: 'WhatsApp', action: 'Webhook Verify Token Regenerated', clientId: null);

        // Returned exactly once, because it must be pasted into Meta's dashboard
        // and is never readable again afterwards.
        return response()->json([
            'success' => true,
            'token'   => $token,
            'notice'  => 'Copy this now — it will not be shown again.',
        ]);
    }

    public function disconnect(): RedirectResponse
    {
        $this->settings->forget();

        $this->activityLog->log(module: 'WhatsApp', action: 'Settings Cleared', clientId: null);

        return back()->with('success', 'WhatsApp credentials removed. Connected numbers keep their own tokens but no new number can be onboarded.');
    }
}
