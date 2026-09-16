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
        // Shown in place of the logo once the sidebar is collapsed to icons.
        $appIcon    = $this->branding->url('app_icon');
        $themeColor = Setting::get('theme_color', '#1F3C88');
        // Null means "follow the theme colour", which is the default.
        $navActiveColor    = Setting::get('nav_active_color');
        $filterActiveColor = Setting::get('filter_active_color');

        $hex = ltrim($themeColor, '#');
        $themeColorDark = sprintf('#%02x%02x%02x',
            max(0, (int) round(hexdec(substr($hex, 0, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 2, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 4, 2)) * .82))
        );

        return view('settings.general', compact(
            'appName', 'appLogo', 'appFavicon', 'appIcon',
            'themeColor', 'themeColorDark', 'navActiveColor', 'filterActiveColor',
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'app_name'    => 'nullable|string|max:80',
            'logo'        => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:512',
            // Not the `image` rule: it rejects .ico, which is still the most
            // common thing people have to hand for a favicon.
            'favicon'     => 'nullable|file|mimes:png,jpg,jpeg,svg,webp,ico|max:256',
            // The collapsed-sidebar mark. Same rules as the favicon: small,
            // square, and .ico is fair game.
            'icon'        => 'nullable|file|mimes:png,jpg,jpeg,svg,webp,ico|max:256',
            'theme_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'nav_active_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'filter_active_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'favicon.mimes' => 'The favicon must be a .ico, .png, .svg, .webp or .jpg file.',
            'icon.mimes'    => 'The sidebar icon must be a .ico, .png, .svg, .webp or .jpg file.',
            'nav_active_color.regex' => 'The active menu colour must be a hex code like #1F3C88.',
            'filter_active_color.regex' => 'The filter colour must be a hex code like #1F3C88.',
        ]);

        if ($request->filled('app_name')) {
            Setting::set('app_name', trim($request->app_name));
        }

        if ($request->filled('theme_color')) {
            // Setting::set already busts the shared settings cache the layout reads.
            Setting::set('theme_color', strtolower($request->theme_color));
        }

        // Cleared rather than stored when it should simply follow the theme, so
        // changing the theme colour keeps moving the menu highlight with it.
        foreach ([
            'nav_active_color'    => 'nav_use_theme',
            'filter_active_color' => 'filter_use_theme',
        ] as $key => $followsTheme) {
            if ($request->boolean($followsTheme)) {
                Setting::set($key, null);
            } elseif ($request->filled($key)) {
                Setting::set($key, strtolower($request->input($key)));
            }
        }

        $this->handleBrandImage($request, 'logo', 'app_logo', 'uploads/logo');
        $this->handleBrandImage($request, 'favicon', 'app_favicon', 'uploads/favicon');
        $this->handleBrandImage($request, 'icon', 'app_icon', 'uploads/icon');

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
