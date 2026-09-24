<?php

namespace App\Notifications;

use App\Models\BugReport;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BugReportSubmitted extends Notification
{
    use BroadcastsToDashboard;

    public function __construct(
        private readonly BugReport $report,
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $reporter = $this->report->reportedBy?->name ?? 'Someone';

        return [
            'title'   => "New {$this->report->severity} severity bug report",
            'message' => "{$reporter} reported an issue: \"{$this->report->subject}\".",
            'bug_report_id' => $this->report->id,
            'url'     => route('bug-reports.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $reporter = $this->report->reportedBy?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("New bug report ({$this->report->severity}): {$this->report->subject}")
            ->line("{$reporter} reported an issue: \"{$this->report->subject}\".")
            ->line($this->report->message)
            ->action('Review Report', route('bug-reports.index'));
    }
}
