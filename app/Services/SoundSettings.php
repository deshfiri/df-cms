<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Storage\StorageSettings;
use App\Support\ShellAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Which sound the app plays for what, set once for everyone in
 * Settings → Sounds.
 *
 * Each event (a chat message, a task, an incoming call, …) picks a sound from
 * the built-in library — two recorded clips and a handful of tones synthesised
 * in the browser — or a file an admin uploaded. Out of the box it plays exactly
 * what it did before this existed: message_alert for chat, notification for
 * everything else, including the call ringtone.
 *
 * Everyone can still mute sounds for themselves from the profile menu; that
 * choice lives in their browser and this never overrides it.
 *
 * Stored as one JSON row, so a save is all-or-nothing.
 */
class SoundSettings
{
    public const KEY = 'alert_sounds';

    /** Where uploaded sounds are kept, on whichever disk was active at the time. */
    public const FOLDER = 'alert-sounds';

    /** An alert is a second or two; a megabyte is already generous. */
    public const MAX_UPLOAD_KB = 1024;

    /** Extension => the type it is served as. Nothing else is accepted. */
    public const UPLOAD_TYPES = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
    ];

    /** What the file's content may be detected as, for each accepted extension. */
    public const UPLOAD_DETECTED = [
        'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave', 'audio/ogg', 'application/ogg',
    ];

    public const CUSTOM = 'custom';
    public const UPLOAD = 'upload';

    /**
     * The events that make a noise, in the order the settings page lists them.
     * `default` is what played before sounds were configurable.
     */
    public const EVENTS = [
        'message'      => ['label' => 'Chat message',     'icon' => 'bi-chat-dots',     'hint' => 'A new message in the internal chat',                'default' => 'file:message_alert', 'volume' => 60],
        'notification' => ['label' => 'Notification',     'icon' => 'bi-bell',          'hint' => 'Anything in the bell that has no sound of its own', 'default' => 'file:notification',  'volume' => 60],
        'task'         => ['label' => 'Task update',      'icon' => 'bi-check2-square', 'hint' => 'A task assigned, handed in or reviewed',            'default' => 'file:notification',  'volume' => 60],
        'workflow'     => ['label' => 'Workflow item',    'icon' => 'bi-diagram-3',     'hint' => 'Work reaching your stage, comments, overdue items', 'default' => 'file:notification',  'volume' => 60],
        'meeting'      => ['label' => 'Meeting',          'icon' => 'bi-calendar-event', 'hint' => 'Meetings scheduled, moved, cancelled or starting', 'default' => 'file:notification',  'volume' => 60],
        'call'         => ['label' => 'Incoming call',    'icon' => 'bi-telephone',     'hint' => 'Repeats until the call is answered or declined',    'default' => 'file:notification',  'volume' => 55, 'loop' => true],
    ];

    /**
     * Built-in sounds. `file:` entries are recordings shipped in public/sounds;
     * `tone:` entries are synthesised by shell-a.js, so they cost no download.
     */
    public const LIBRARY = [
        'file:message_alert' => ['label' => 'Message alert',      'file' => 'sounds/message_alert.mp3'],
        'file:notification'  => ['label' => 'Notification',       'file' => 'sounds/notification.mp3'],
        'tone:chime'         => ['label' => 'Chime'],
        'tone:ping'          => ['label' => 'Ping'],
        'tone:pop'           => ['label' => 'Soft pop'],
        'tone:triple'        => ['label' => 'Triple beep'],
        'tone:ring'          => ['label' => 'Phone ring'],
    ];

    /**
     * Notification class => event. Anything not listed is a plain notification.
     * Matched on the class name's prefix, so a new Task… or FlowItem…
     * notification picks up the right sound without an edit here.
     */
    private const NOTIFICATION_PREFIXES = [
        'Task'     => 'task',
        'FlowItem' => 'workflow',
        'Stage'    => 'workflow',
        'Meeting'  => 'meeting',
    ];

    public function __construct(private readonly StorageSettings $storage)
    {
    }

    /**
     * The saved configuration, filled out with defaults and cleaned: an unknown
     * sound, or a custom one whose file is gone, falls back to the default.
     *
     * @return array{enabled: bool, events: array<string, array{on: bool, sound: string, volume: int, custom: ?array}>}
     */
    public function config(): array
    {
        $saved = json_decode((string) Setting::get(self::KEY, ''), true);
        $saved = is_array($saved) ? $saved : [];

        $events = [];
        foreach (self::EVENTS as $key => $meta) {
            $row    = is_array($saved['events'][$key] ?? null) ? $saved['events'][$key] : [];
            $custom = $this->cleanCustom($row['custom'] ?? null);
            $sound  = (string) ($row['sound'] ?? $meta['default']);

            if (!isset(self::LIBRARY[$sound]) && !($sound === self::CUSTOM && $custom)) {
                $sound = $meta['default'];
            }

            $events[$key] = [
                'on'     => (bool) ($row['on'] ?? true),
                'sound'  => $sound,
                'volume' => max(0, min(100, (int) ($row['volume'] ?? $meta['volume']))),
                'custom' => $custom,
            ];
        }

        return ['enabled' => (bool) ($saved['enabled'] ?? true), 'events' => $events];
    }

    /**
     * What the browser needs to play each sound, and nothing more: no paths,
     * no disks. Emitted into every page by the layout.
     */
    public function forBrowser(): array
    {
        $config = $this->config();
        $events = [];

        foreach ($config['events'] as $key => $row) {
            $events[$key] = $this->playable($key, $row['sound'], $row['custom']) + [
                'on'     => $row['on'] && $row['volume'] > 0,
                'volume' => round($row['volume'] / 100, 2),
                'loop'   => !empty(self::EVENTS[$key]['loop']),
            ];
        }

        return ['enabled' => $config['enabled'], 'events' => $events];
    }

    /**
     * How to play one choice: a URL, or the name of a synthesised tone.
     *
     * @return array{src: ?string, tone: ?string}
     */
    public function playable(string $event, string $sound, ?array $custom): array
    {
        if ($sound === self::CUSTOM && $custom) {
            return ['src' => $this->customUrl($event, $custom), 'tone' => null];
        }

        $sound = isset(self::LIBRARY[$sound]) ? $sound : self::EVENTS[$event]['default'];

        return str_starts_with($sound, 'tone:')
            ? ['src' => null, 'tone' => substr($sound, 5)]
            : ['src' => ShellAsset::url(self::LIBRARY[$sound]['file']), 'tone' => null];
    }

    /** Every built-in, playable, for the settings page's preview button. */
    public function library(): array
    {
        return collect(self::LIBRARY)->map(fn (array $item, string $key) => [
            'label' => $item['label'],
            'kind'  => str_starts_with($key, 'tone:') ? 'Tones' : 'Recordings',
            'src'   => isset($item['file']) ? ShellAsset::url($item['file']) : null,
            'tone'  => str_starts_with($key, 'tone:') ? substr($key, 5) : null,
        ])->all();
    }

    /**
     * Save a new configuration.
     *
     * $input is already validated. An event set to UPLOAD takes its file from
     * $uploads; the file it replaces is removed once the new one is saved, since
     * nothing else points at it.
     *
     * @param  array{enabled: bool, events: array<string, array{on: bool, sound: string, volume: int}>}  $input
     * @param  array<string, UploadedFile>  $uploads
     * @return array{before: array, after: array}
     */
    public function save(array $input, array $uploads): array
    {
        $before   = $this->config();
        $replaced = [];
        $events   = [];

        foreach (self::EVENTS as $key => $meta) {
            $row    = $input['events'][$key] ?? [];
            $sound  = (string) ($row['sound'] ?? $before['events'][$key]['sound']);
            $custom = $before['events'][$key]['custom'];

            if ($sound === self::UPLOAD) {
                // The controller has already refused an UPLOAD with no file.
                $stored = $this->storeUpload($uploads[$key]);
                if ($custom) {
                    $replaced[] = $custom;
                }
                $custom = $stored;
                $sound  = self::CUSTOM;
            }

            $events[$key] = [
                'on'     => (bool) ($row['on'] ?? false),
                'sound'  => $sound,
                'volume' => max(0, min(100, (int) ($row['volume'] ?? $meta['volume']))),
                'custom' => $custom,
            ];
        }

        Setting::set(self::KEY, json_encode(['enabled' => (bool) $input['enabled'], 'events' => $events]));

        foreach ($replaced as $old) {
            $this->deleteFile($old);
        }

        return ['before' => $before, 'after' => $this->config()];
    }

    /** The uploaded file behind an event, if it has one. */
    public function customFile(string $event): ?array
    {
        return $this->config()['events'][$event]['custom'] ?? null;
    }

    /** Which event's sound a bell notification of this class plays. */
    public static function eventForNotification(?string $type): string
    {
        $class = class_basename((string) $type);

        foreach (self::NOTIFICATION_PREFIXES as $prefix => $event) {
            if (str_starts_with($class, $prefix)) {
                return $event;
            }
        }

        return 'notification';
    }

    /** A short account of a configuration, for the activity log. */
    public static function describe(array $config): array
    {
        return [
            'enabled' => $config['enabled'],
            'events'  => collect($config['events'])->map(fn (array $row) => [
                'on'     => $row['on'],
                'sound'  => $row['sound'] === self::CUSTOM ? 'custom: ' . ($row['custom']['name'] ?? '?') : $row['sound'],
                'volume' => $row['volume'],
            ])->all(),
        ];
    }

    private function storeUpload(UploadedFile $file): array
    {
        $ext  = strtolower($file->getClientOriginalExtension());
        $disk = $this->storage->activeDisk();
        $path = $file->storeAs(self::FOLDER, Str::uuid() . '.' . $ext, $disk);

        return [
            'path' => $path,
            'disk' => $disk,
            'name' => Str::limit(basename($file->getClientOriginalName()), 120, ''),
            'mime' => self::UPLOAD_TYPES[$ext],
            'size' => (int) $file->getSize(),
        ];
    }

    private function deleteFile(array $custom): void
    {
        try {
            Storage::disk($custom['disk'] ?: 'local')->delete($custom['path']);
        } catch (\Throwable $e) {
            // A leftover file is harmless; a failed save over it would not be.
            report($e);
        }
    }

    private function cleanCustom(mixed $custom): ?array
    {
        if (!is_array($custom) || empty($custom['path']) || !is_string($custom['path'])) {
            return null;
        }

        $ext = strtolower(pathinfo($custom['path'], PATHINFO_EXTENSION));
        if (!isset(self::UPLOAD_TYPES[$ext])) {
            return null;
        }

        return [
            'path' => $custom['path'],
            'disk' => (string) ($custom['disk'] ?? 'local') ?: 'local',
            'name' => (string) ($custom['name'] ?? basename($custom['path'])),
            'mime' => self::UPLOAD_TYPES[$ext],
            'size' => (int) ($custom['size'] ?? 0),
        ];
    }

    /** Versioned by the file, so a browser can cache it until it is replaced. */
    private function customUrl(string $event, array $custom): string
    {
        return route('sounds.play', ['event' => $event, 'v' => substr(sha1($custom['disk'] . '|' . $custom['path']), 0, 12)]);
    }
}
