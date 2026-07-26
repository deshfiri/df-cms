<?php

namespace App\Console\Commands;

use App\Models\ClientActionRequest;
use App\Models\Invoice;
use App\Notifications\Portal\DeadlineApproaching;
use App\Notifications\Portal\PaymentDueSoon;
use App\Services\Portal\NotifiesPortalUsers;
use Illuminate\Console\Command;

class SendPortalDeadlineReminders extends Command
{
    use NotifiesPortalUsers;

    protected $signature = 'portal:send-deadline-reminders';
    protected $description = 'Notifies clients of action requests due soon and invoices due soon (3-day window)';

    public function handle(): int
    {
        $windowEnd = now()->addDays(3)->toDateString();

        $actionRequests = ClientActionRequest::whereIn('status', [
                ClientActionRequest::STATUS_PENDING,
                ClientActionRequest::STATUS_NEED_REVISION,
            ])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), $windowEnd])
            ->with('client')
            ->get();

        foreach ($actionRequests as $actionRequest) {
            $this->notifyPortalUsers($actionRequest->client, new DeadlineApproaching($actionRequest));
        }

        $invoices = Invoice::whereNotIn('status', array_merge(Invoice::$terminalStatuses, [Invoice::STATUS_PAID]))
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), $windowEnd])
            ->with('client')
            ->get();

        foreach ($invoices as $invoice) {
            $this->notifyPortalUsers($invoice->client, new PaymentDueSoon($invoice));
        }

        $this->info('Sent ' . $actionRequests->count() . ' action deadline reminder(s) and ' . $invoices->count() . ' payment reminder(s).');

        return self::SUCCESS;
    }
}
