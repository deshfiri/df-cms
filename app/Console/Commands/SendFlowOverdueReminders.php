<?php

namespace App\Console\Commands;

use App\Models\FlowItem;
use App\Notifications\FlowItemOverdue;
use Illuminate\Console\Command;

/**
 * Daily SLA nudge: notify the current-stage assignees of every open workflow
 * item whose due date has passed, so overdue work doesn't sit silently.
 */
class SendFlowOverdueReminders extends Command
{
    protected $signature = 'flow:overdue-reminders';

    protected $description = 'Notify assignees of workflow items that are past their due date.';

    public function handle(): int
    {
        $items = FlowItem::with(['currentStage.users:id,name,is_active'])
            ->where('status', FlowItem::STATUS_OPEN)
            ->whereNotNull('due_date')
            ->whereNotNull('current_stage_id')
            ->whereDate('due_date', '<', today())
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $recipients = optional($item->currentStage)->users?->where('is_active', true) ?? collect();
            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify(new FlowItemOverdue($item));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("Overdue reminders: {$sent} notification(s) across {$items->count()} item(s).");

        return self::SUCCESS;
    }
}
