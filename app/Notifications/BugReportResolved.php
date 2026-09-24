<?php

namespace App\Notifications;

use App\Models\BugReport;
use App\Notifications\Concerns\BroadcastsToDashboard;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BugReportResolved extends Notification
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
        $verb = $this->report->status === BugReport::STATUS_RESOLVED ? 'resolved' : 'closed';
        $message = "\"{$this->report->subject}\" was marked {$verb}";
        if ($this->report->response_note) {
            $message .= ": {$this->report->response_note}";
        } else {
            $message .= '.';
        }

        return [
            'title'   => "Your bug report was {$verb}",
            'message' => $message,
            'bug_report_id' => $this->report->id,
            'url'     => route('bug-reports.index'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $verb = $this->report->status === BugReport::STATUS_RESOLVED ? 'resolved' : 'closed';

        $mail = (new MailMessage)
            ->subject("Your bug report was {$verb}: {$this->report->subject}")
            ->line("Your report \"{$this->report->subject}\" was marked {$verb}.");

        if ($this->report->response_note) {
            $mail->line("Note: {$this->report->response_note}");
        }

        return $mail->action('View Report', route('bug-reports.index'));
    }
}
