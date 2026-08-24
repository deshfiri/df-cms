<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Storage\BrandingAssetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(
        private readonly BrandingAssetService $branding,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403);
            return $next($request);
        });
    }

    public function index()
    {
        $appName    = Setting::get('app_name', 'DFCP COMS');
        // Resolved through the service: these may now live on a CDN, in which
        // case the stored value is a remote path rather than a public/ one.
        $appLogo    = $this->branding->url('app_logo');
        $appFavicon = $this->branding->url('app_favicon');
        $themeColor = Setting::get('theme_color', '#1F3C88');

        $hex = ltrim($themeColor, '#');
        $themeColorDark = sprintf('#%02x%02x%02x',
            max(0, (int) round(hexdec(substr($hex, 0, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 2, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 4, 2)) * .82))
        );

        return view('settings.general', compact('appName', 'appLogo', 'appFavicon', 'themeColor', 'themeColorDark'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'app_name'    => 'nullable|string|max:80',
            'logo'        => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:512',
            // Not the `image` rule: it rejects .ico, which is still the most
            // common thing people have to hand for a favicon.
            'favicon'     => 'nullable|file|mimes:png,jpg,jpeg,svg,webp,ico|max:256',
            'theme_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'favicon.mimes' => 'The favicon must be a .ico, .png, .svg, .webp or .jpg file.',
        ]);

        if ($request->filled('app_name')) {
            Setting::set('app_name', trim($request->app_name));
        }

        if ($request->filled('theme_color')) {
            // Setting::set already busts the shared settings cache the layout reads.
            Setting::set('theme_color', strtolower($request->theme_color));
        }

        $this->handleBrandImage($request, 'logo', 'app_logo', 'uploads/logo');
        $this->handleBrandImage($request, 'favicon', 'app_favicon', 'uploads/favicon');

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Upload / replace / remove one branding image.
     *
     * The logo and the favicon do exactly the same three things, so they share
     * this rather than keeping two copies that drift apart.
     *
     * Where the file actually lands is BrandingAssetService's decision: the
     * active CDN when it can serve a public URL, this server otherwise. These
     * are the only genuinely public uploads in the application — a browser
     * fetches them before anyone has logged in — so they cannot go through the
     * private, proxied path every other upload uses.
     *
     * @param  string  $field      form field name, also used as the "remove_" flag
     * @param  string  $settingKey where the path is stored
     * @param  string  $directory  folder, used under public/ and on the CDN alike
     */
    private function handleBrandImage(Request $request, string $field, string $settingKey, string $directory): void
    {
        if ($request->hasFile($field)) {
            $this->branding->store($request->file($field), $settingKey, $directory);
        }

        if ($request->boolean('remove_' . $field)) {
            $this->branding->delete($settingKey);
        }
    }
}
