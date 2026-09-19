<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\SoundSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Settings → Sounds: which sound plays for what, for everyone.
 *
 * Held by 'manage sound settings' — Super Admin out of the box, and grantable
 * to any role in Settings → Roles. Every change goes to the activity log.
 */
class SoundSettingsController extends Controller
{
    public const PERMISSION = 'manage sound settings';

    public function __construct(
        private readonly SoundSettings $sounds,
        private readonly ActivityLogService $activityLog,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->can(self::PERMISSION), 403);

            return $next($request);
        });
    }

    public function index()
    {
        $config = $this->sounds->config();

        // What each event plays right now, so the page can preview a saved
        // custom sound without re-uploading it.
        $saved = collect($config['events'])->map(fn (array $row, string $event) => $row['custom']
            ? $this->sounds->playable($event, SoundSettings::CUSTOM, $row['custom'])
            : null);

        return view('settings.sounds', [
            'config'  => $config,
            'events'  => SoundSettings::EVENTS,
            'library' => $this->sounds->library(),
            'saved'   => $saved,
            'maxKb'   => SoundSettings::MAX_UPLOAD_KB,
            'types'   => array_keys(SoundSettings::UPLOAD_TYPES),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $choices    = [...array_keys(SoundSettings::LIBRARY), SoundSettings::CUSTOM, SoundSettings::UPLOAD];
        $rules      = ['enabled' => ['nullable', 'boolean']];
        $attributes = [];

        foreach (SoundSettings::EVENTS as $event => $meta) {
            $rules["events.{$event}"]        = ['required', 'array'];
            $rules["events.{$event}.on"]     = ['nullable', 'boolean'];
            $rules["events.{$event}.sound"]  = ['required', 'string', Rule::in($choices)];
            $rules["events.{$event}.volume"] = ['required', 'integer', 'min:0', 'max:100'];
            $rules["uploads.{$event}"]       = [
                'nullable', 'file', 'max:' . SoundSettings::MAX_UPLOAD_KB,
                // The name and the content both have to be audio.
                'extensions:' . implode(',', array_keys(SoundSettings::UPLOAD_TYPES)),
                'mimetypes:' . implode(',', SoundSettings::UPLOAD_DETECTED),
            ];

            $attributes["events.{$event}.sound"]  = $meta['label'] . ' sound';
            $attributes["events.{$event}.volume"] = $meta['label'] . ' volume';
            $attributes["uploads.{$event}"]       = $meta['label'] . ' file';
        }

        $data = $request->validate($rules, [
            'uploads.*.max'        => 'Keep the :attribute under ' . SoundSettings::MAX_UPLOAD_KB . ' KB — an alert only needs a second or two.',
            'uploads.*.extensions' => 'The :attribute must be an MP3, WAV or OGG.',
            'uploads.*.mimetypes'  => 'The :attribute does not look like an MP3, WAV or OGG sound.',
        ], $attributes);

        $current = $this->sounds->config();
        $input   = ['enabled' => $request->boolean('enabled'), 'events' => []];
        $uploads = [];
        $errors  = [];

        foreach (SoundSettings::EVENTS as $event => $meta) {
            $sound = $data['events'][$event]['sound'];
            $file  = $request->file("uploads.{$event}");

            if ($sound === SoundSettings::UPLOAD) {
                if (!$file) {
                    $errors["uploads.{$event}"] = "Choose a file for {$meta['label']}, or pick one of the built-in sounds.";
                }
                $uploads[$event] = $file;
            } elseif ($sound === SoundSettings::CUSTOM && !$current['events'][$event]['custom']) {
                $errors["events.{$event}.sound"] = "{$meta['label']} has no uploaded sound any more. Upload one, or pick a built-in sound.";
            }

            $input['events'][$event] = [
                'on'     => $request->boolean("events.{$event}.on"),
                'sound'  => $sound,
                'volume' => (int) $data['events'][$event]['volume'],
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $result = $this->sounds->save($input, $uploads);

        $this->activityLog->log(
            module: 'Settings',
            action: 'Alert Sounds Changed',
            clientId: null,
            oldValue: SoundSettings::describe($result['before']),
            newValue: SoundSettings::describe($result['after']),
        );

        return redirect()->route('settings.sounds')
            ->with('success', 'Sounds saved. Everyone hears the new sounds the next time a page loads.');
    }
}
