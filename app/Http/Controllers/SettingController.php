<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(auth()->user()->hasRole('Super Admin'), 403);
            return $next($request);
        });
    }

    public function index()
    {
        $appName    = Setting::get('app_name', 'DFCP COMS');
        $appLogo    = Setting::get('app_logo');
        $appFavicon = Setting::get('app_favicon');
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
     * @param  string  $field      form field name, also used as the "remove_" flag
     * @param  string  $settingKey where the public path is stored
     * @param  string  $directory  public/ subdirectory to write into
     */
    private function handleBrandImage(Request $request, string $field, string $settingKey, string $directory): void
    {
        $deleteExisting = function () use ($settingKey) {
            $old = Setting::get($settingKey);

            if ($old && is_file(public_path($old))) {
                @unlink(public_path($old));
            }
        };

        if ($request->hasFile($field)) {
            $deleteExisting();

            $file = $request->file($field);
            $dir  = public_path($directory);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Timestamped so a replacement gets a new URL and browsers do not
            // keep showing the previous one from cache.
            $filename = $field . '_' . time() . '.' . strtolower($file->getClientOriginalExtension() ?: 'png');
            $file->move($dir, $filename);

            Setting::set($settingKey, $directory . '/' . $filename);
        }

        if ($request->boolean('remove_' . $field)) {
            $deleteExisting();
            Setting::set($settingKey, null);
        }
    }
}
