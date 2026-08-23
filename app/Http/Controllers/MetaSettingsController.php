<?php

namespace App\Http\Controllers;

use App\Models\BrandIntegration;
use App\Services\Meta\MetaAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super Admin settings for the Meta app credentials.
 *
 * These identify *this application* to Meta and are shared by every brand.
 * Each brand's own access token is granted separately, on the brand's page
 * under Marketing — nothing here touches those.
 */
class MetaSettingsController extends Controller
{
    public function __construct(
        private readonly MetaAppSettings $settings,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403, 'Super Admin only.');

            return $next($request);
        });
    }

    public function index()
    {
        return view('settings.meta', [
            'settings'    => $this->settings,
            'redirectUri' => $this->settings->redirectUri(),
            // Brands already connected through these credentials; changing the
            // app secret invalidates them, so the page says how many.
            'connected'   => BrandIntegration::where('platform', BrandIntegration::PLATFORM_META)
                ->where('status', BrandIntegration::STATUS_CONNECTED)
                ->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id'      => ['nullable', 'string', 'max:100'],
            'app_secret'  => ['nullable', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'regex:/^v\d+\.\d+$/'],
            'scopes'      => ['nullable', 'string', 'max:500'],
        ], [
            'api_version.regex' => 'The API version looks like v21.0.',
        ]);

        $this->settings->putCredentials($data['app_id'] ?? null, $data['app_secret'] ?? null);
        $this->settings->putApiVersion($data['api_version'] ?? null);
        $this->settings->putScopes($data['scopes'] ?? null);

        return back()->with('success', 'Meta settings saved.');
    }
}
