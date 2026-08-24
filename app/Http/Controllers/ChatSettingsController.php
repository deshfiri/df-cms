<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogService;
use App\Services\Chat\ChatAttachmentPruner;
use App\Services\Chat\ChatRetentionSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → Chat: how long attachments are kept.
 *
 * Changing this decides whether files get deleted, so it sits with the other
 * Super Admin settings and every change is written to the activity log.
 */
class ChatSettingsController extends Controller
{
    public function __construct(
        private readonly ChatRetentionSettings $retention,
        private readonly ChatAttachmentPruner $pruner,
        private readonly ActivityLogService $activityLog,
    ) {
        $this->middleware(function ($request, $next) {
            abort_unless($request->user()->hasRole('Super Admin'), 403);

            return $next($request);
        });
    }

    public function index()
    {
        return view('settings.chat', [
            'enabled'   => $this->retention->enabled(),
            'days'      => $this->retention->days(),
            'summary'   => $this->retention->summary(),
            'inventory' => $this->pruner->inventory(),
            'minDays'   => ChatRetentionSettings::MIN_DAYS,
            'maxDays'   => ChatRetentionSettings::MAX_DAYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'retention_enabled' => ['nullable', 'boolean'],
            // Required only when the policy is on, so switching it off does not
            // demand a number nobody is going to use.
            'retention_days' => [
                'nullable',
                'required_if:retention_enabled,1',
                'integer',
                'min:' . ChatRetentionSettings::MIN_DAYS,
                'max:' . ChatRetentionSettings::MAX_DAYS,
            ],
        ], [
            'retention_days.required_if' => 'Say how many days attachments should be kept.',
            'retention_days.min'         => 'Keep attachments for at least one day.',
        ]);

        $enabled = $request->boolean('retention_enabled');
        $days    = isset($data['retention_days']) ? (int) $data['retention_days'] : null;

        $was = ['enabled' => $this->retention->enabled(), 'days' => $this->retention->days()];

        $this->retention->put($enabled, $days);

        $this->activityLog->log(
            module: 'Chat',
            action: 'Attachment Retention Changed',
            clientId: null,
            oldValue: $was,
            newValue: ['enabled' => $enabled, 'days' => $this->retention->days()],
        );

        return back()->with('success', $this->retention->summary());
    }

    /**
     * What the policy would remove right now.
     *
     * Deliberately separate from running it: deleting files is not something to
     * discover by pressing a button labelled "preview".
     */
    public function preview(): JsonResponse
    {
        if (!$this->retention->enabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Retention is switched off, so nothing would be deleted.',
            ]);
        }

        $result = $this->pruner->preview();

        return response()->json([
            'success' => true,
            'count'   => $result['eligible'],
            'size'    => ChatAttachmentPruner::formatBytes($result['bytes']),
            'cutoff'  => $this->retention->cutoff()->format('d M Y'),
        ]);
    }

    /** Apply the policy now, rather than waiting for tonight's scheduled run. */
    public function runNow(): JsonResponse
    {
        if (!$this->retention->enabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Switch retention on before running it.',
            ], 422);
        }

        $result = $this->pruner->prune();

        return response()->json([
            'success' => true,
            'message' => $result['purged'] . ' attachment(s) deleted, '
                . ChatAttachmentPruner::formatBytes($result['bytes']) . ' freed.'
                . ($result['failed'] > 0 ? ' ' . $result['failed'] . ' could not be removed from storage.' : ''),
        ]);
    }
}
