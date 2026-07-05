<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMeeting;
use App\Models\User;
use App\Notifications\MeetingCancelled;
use App\Notifications\MeetingRescheduled;
use App\Notifications\MeetingScheduled;
use App\Services\Contracts\GoogleCalendarServiceInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class MeetingService
{
    private const WORKFLOW_STAGE_CODE = 'meeting_scheduled';

    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly GoogleCalendarServiceInterface $googleCalendar,
        private readonly WorkflowService $workflowService,
        private readonly ChangeApprovalService $changeApproval,
    ) {}

    public function create(Client $client, array $data, User $actor): ClientMeeting
    {
        return DB::transaction(function () use ($client, $data, $actor) {
            $meeting = ClientMeeting::create(array_merge($data, [
                'client_id'  => $client->id,
                'created_by' => $actor->id,
                'status'     => 'Scheduled',
            ]));

            $this->syncCreateToGoogle($meeting);

            $this->activityLog->log('Meeting', 'Scheduled', $client->id, null, [
                'title' => $meeting->title,
                'scheduled_at' => $meeting->scheduled_at->toDateTimeString(),
            ]);

            $this->workflowService->systemSubmitByCode($client, self::WORKFLOW_STAGE_CODE, $actor, 'Meeting booked');

            $meeting->load(['createdBy:id,name', 'assignedUser:id,name', 'client:id,client_name,contact_email']);
            $this->notifyParticipants($meeting, new MeetingScheduled($meeting));

            return $meeting;
        });
    }

    public function update(ClientMeeting $meeting, array $data, User $actor): ClientMeeting
    {
        // Must run before anything below — this method syncs to Google Calendar
        // and notifies participants as side effects, which must never fire for
        // an edit that hasn't actually been approved yet.
        $this->changeApproval->guard(ClientMeeting::class, $meeting->id, $meeting->only(array_keys($data)), $data, $actor);

        return DB::transaction(function () use ($meeting, $data, $actor) {
            $previousTime = $meeting->scheduled_at->copy();
            $old = $meeting->only(['title', 'scheduled_at', 'type', 'status']);

            $meeting->update($data);

            if ($meeting->wasChanged('scheduled_at')) {
                $meeting->update(['status' => 'Rescheduled']);
                $this->googleCalendar->updateEvent($meeting);

                $this->activityLog->log(
                    'Meeting',
                    'Rescheduled',
                    $meeting->client_id,
                    ['scheduled_at' => $previousTime->toDateTimeString()],
                    ['scheduled_at' => $meeting->scheduled_at->toDateTimeString()]
                );

                $meeting->load(['createdBy:id,name', 'assignedUser:id,name', 'client:id,client_name,contact_email']);
                $this->notifyParticipants($meeting, new MeetingRescheduled($meeting, $previousTime));
                // Workflow stage intentionally left as-is (still Submitted, not re-approved) —
                // it must remain locked until the meeting is actually completed.
            } else {
                $this->googleCalendar->updateEvent($meeting);
                $this->activityLog->log('Meeting', 'Updated', $meeting->client_id, $old, $meeting->only(['title', 'scheduled_at', 'type', 'status']));
            }

            return $meeting->fresh(['createdBy:id,name', 'assignedUser:id,name', 'client:id,client_name']);
        });
    }

    public function cancel(ClientMeeting $meeting, User $actor): ClientMeeting
    {
        return DB::transaction(function () use ($meeting, $actor) {
            $meeting->update(['status' => 'Cancelled']);
            $this->googleCalendar->cancelEvent($meeting);

            $this->activityLog->log('Meeting', 'Cancelled', $meeting->client_id, null, ['title' => $meeting->title]);

            $this->workflowService->systemRevisionByCode($meeting->client, self::WORKFLOW_STAGE_CODE, $actor, 'Meeting cancelled');

            $meeting->load(['createdBy:id,name', 'assignedUser:id,name', 'client:id,client_name,contact_email']);
            $this->notifyParticipants($meeting, new MeetingCancelled($meeting));

            return $meeting;
        });
    }

    public function markNoShow(ClientMeeting $meeting, User $actor): ClientMeeting
    {
        return DB::transaction(function () use ($meeting, $actor) {
            $meeting->update(['status' => 'No Show']);

            $this->activityLog->log('Meeting', 'No Show', $meeting->client_id, null, ['title' => $meeting->title]);
            $this->workflowService->systemRevisionByCode($meeting->client, self::WORKFLOW_STAGE_CODE, $actor, 'Meeting no-show');

            return $meeting->fresh();
        });
    }

    public function complete(ClientMeeting $meeting, User $actor, ?string $notes): ClientMeeting
    {
        return DB::transaction(function () use ($meeting, $actor, $notes) {
            $meeting->update([
                'status'       => 'Completed',
                'completed_at' => now(),
                'completed_by' => $actor->id,
                'notes'        => $notes ?? $meeting->notes,
            ]);

            $this->activityLog->log('Meeting', 'Completed', $meeting->client_id, null, ['title' => $meeting->title]);

            $this->workflowService->systemApproveStageByCode($meeting->client, self::WORKFLOW_STAGE_CODE, $actor, 'Meeting completed');

            return $meeting->fresh(['createdBy:id,name']);
        });
    }

    /**
     * Admin-only: force a meeting to Completed regardless of who normally
     * would be allowed to (permission gate lives in the controller).
     */
    public function forceComplete(ClientMeeting $meeting, User $actor, ?string $notes): ClientMeeting
    {
        $meeting = $this->complete($meeting, $actor, $notes);
        $this->activityLog->log('Meeting', 'Force Completed (Admin)', $meeting->client_id, null, ['title' => $meeting->title]);

        return $meeting;
    }

    /**
     * Admin-only: regenerate the Google Meet link, replacing the existing
     * calendar event with a fresh one.
     */
    public function regenerateMeetLink(ClientMeeting $meeting, User $actor): ClientMeeting
    {
        return DB::transaction(function () use ($meeting, $actor) {
            if ($meeting->google_event_id) {
                $this->googleCalendar->deleteEvent($meeting);
            }

            $result = $this->googleCalendar->createEvent($meeting);

            $meeting->update([
                'google_event_id' => $result['event_id'] ?? null,
                'google_meet_url' => $result['meet_url'] ?? null,
            ]);

            $this->activityLog->log('Meeting', 'Meet Link Regenerated (Admin)', $meeting->client_id, null, ['title' => $meeting->title]);

            return $meeting->fresh();
        });
    }

    public function delete(ClientMeeting $meeting): void
    {
        $this->googleCalendar->deleteEvent($meeting);
        $this->activityLog->log('Meeting', 'Deleted', $meeting->client_id, ['title' => $meeting->title]);
        $meeting->delete();
    }

    public function findConflict(int $clientId, string $scheduledAt, int $durationMinutes, ?int $excludeId = null): ?ClientMeeting
    {
        $start = Carbon::parse($scheduledAt);
        $end   = $start->copy()->addMinutes($durationMinutes);

        return ClientMeeting::where('client_id', $clientId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('scheduled_at', '<', $end)
            ->whereRaw('DATE_ADD(scheduled_at, INTERVAL duration_minutes MINUTE) > ?', [$start->toDateTimeString()])
            ->first();
    }

    /**
     * Business-hours (9:00-18:00) slot grid for one staff member on one day,
     * stepped every 15 minutes, marking a slot unavailable if it overlaps an
     * existing meeting of theirs or falls in the past.
     */
    public function availableSlots(int $userId, string $date, int $durationMinutes): array
    {
        $day           = Carbon::parse($date)->startOfDay();
        $businessStart = $day->copy()->setTime(9, 0);
        $businessEnd   = $day->copy()->setTime(18, 0);

        $booked = ClientMeeting::where('assigned_to', $userId)
            ->whereIn('status', ['Pending', 'Scheduled'])
            ->whereBetween('scheduled_at', [$day, $day->copy()->endOfDay()])
            ->get(['scheduled_at', 'duration_minutes'])
            ->map(fn ($m) => [
                'start' => $m->scheduled_at,
                'end'   => $m->scheduled_at->copy()->addMinutes($m->duration_minutes),
            ]);

        $slots  = [];
        $cursor = $businessStart->copy();

        while ($cursor->copy()->addMinutes($durationMinutes)->lte($businessEnd)) {
            $slotStart = $cursor->copy();
            $slotEnd   = $slotStart->copy()->addMinutes($durationMinutes);

            $overlaps = $booked->contains(fn ($b) => $slotStart->lt($b['end']) && $slotEnd->gt($b['start']));

            $slots[] = [
                'time'      => $slotStart->format('H:i'),
                'label'     => $slotStart->format('g:i A'),
                'available' => !$overlaps && !$slotStart->lt(now()),
            ];

            $cursor->addMinutes(15);
        }

        return $slots;
    }

    private function syncCreateToGoogle(ClientMeeting $meeting): void
    {
        $result = $this->googleCalendar->createEvent($meeting);
        if ($result) {
            $meeting->update([
                'google_event_id' => $result['event_id'],
                'google_meet_url' => $result['meet_url'],
            ]);
        }
    }

    private function notifyParticipants(ClientMeeting $meeting, $notification): void
    {
        $recipients = collect([$meeting->assignedUser, $meeting->createdBy])
            ->filter()
            ->unique('id');

        Notification::send($recipients, $notification);

        if ($meeting->client?->contact_email) {
            Notification::route('mail', $meeting->client->contact_email)->notify($notification);
        }
    }
}
