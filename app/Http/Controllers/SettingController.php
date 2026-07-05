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
        $themeColor = Setting::get('theme_color', '#1F3C88');

        $hex = ltrim($themeColor, '#');
        $themeColorDark = sprintf('#%02x%02x%02x',
            max(0, (int) round(hexdec(substr($hex, 0, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 2, 2)) * .82)),
            max(0, (int) round(hexdec(substr($hex, 4, 2)) * .82))
        );

        return view('settings.general', compact('appName', 'appLogo', 'themeColor', 'themeColorDark'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'app_name'    => 'nullable|string|max:80',
            'logo'        => 'nullable|image|mimes:png,jpg,jpeg,svg,webp|max:512',
            'theme_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        if ($request->filled('app_name')) {
            Setting::set('app_name', trim($request->app_name));
        }

        if ($request->filled('theme_color')) {
            Setting::set('theme_color', strtolower($request->theme_color));
            // Also bust the layout cache key
            \Illuminate\Support\Facades\Cache::forget('setting_theme_color');
        }

        if ($request->hasFile('logo')) {
            // Delete old logo file if it exists
            $old = Setting::get('app_logo');
            if ($old && file_exists(public_path($old))) {
                @unlink(public_path($old));
            }

            $file = $request->file('logo');
            $dir  = public_path('uploads/logo');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move($dir, $filename);
            Setting::set('app_logo', 'uploads/logo/' . $filename);
        }

        if ($request->boolean('remove_logo')) {
            $old = Setting::get('app_logo');
            if ($old && file_exists(public_path($old))) {
                @unlink(public_path($old));
            }
            Setting::set('app_logo', null);
        }

        return back()->with('success', 'Settings saved.');
    }
}
