<?php

namespace App\Console\Commands;

use App\Models\ClientMeeting;
use App\Notifications\MeetingReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendMeetingReminders extends Command
{
    protected $signature = 'meetings:send-reminders';

    protected $description = 'Send 24h/1h/15m reminders for upcoming meetings that have not been reminded yet';

    /** Reminder tiers: [minutes-before-meeting, label, column] */
    private const TIERS = [
        [1440, '24 hours', 'reminder_24h_sent_at'],
        [60,   '1 hour',   'reminder_1h_sent_at'],
        [15,   '15 minutes', 'reminder_15m_sent_at'],
    ];

    public function handle(): int
    {
        $sent = 0;

        foreach (self::TIERS as [$minutesBefore, $label, $column]) {
            $windowStart = now()->addMinutes($minutesBefore);
            $windowEnd   = $windowStart->copy()->addMinutes(5);

            $meetings = ClientMeeting::with(['assignedUser:id,name,email', 'createdBy:id,name,email', 'client:id,client_name,contact_email'])
                ->whereIn('status', ClientMeeting::$openStatuses)
                ->whereNull($column)
                ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
                ->get();

            foreach ($meetings as $meeting) {
                $recipients = collect([$meeting->assignedUser, $meeting->createdBy])->filter()->unique('id');
                Notification::send($recipients, new MeetingReminder($meeting, $label));

                if ($meeting->client?->contact_email) {
                    Notification::route('mail', $meeting->client->contact_email)->notify(new MeetingReminder($meeting, $label));
                }

                $meeting->update([$column => now()]);
                $sent++;
            }
        }

        $this->info("Sent {$sent} meeting reminder(s).");

        return self::SUCCESS;
    }
}
