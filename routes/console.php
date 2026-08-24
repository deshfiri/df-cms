<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('meetings:send-reminders')->everyFiveMinutes();
Schedule::command('portal:mark-overdue-invoices')->daily();
Schedule::command('portal:send-deadline-reminders')->daily();

// Finalize the just-ended month's KPI scores into monthly_performance_snapshots.
Schedule::command('performance:snapshot --previous')->monthlyOn(1, '02:00');

// Daily nudge for overdue workflow items.
Schedule::command('flow:overdue-reminders')->dailyAt('08:00');

// Chat attachment retention. Safe to schedule everywhere: the command is a
// no-op until an administrator switches the policy on in Settings → Chat.
// Runs in the small hours because it deletes files and can touch a lot of them.
Schedule::command('chat:prune-attachments')->dailyAt('03:30')->withoutOverlapping();

// Settle abandoned audio calls. Runs every minute — a "ringing" row that never
// resolves blocks both participants from placing any further call.
Schedule::command('calls:reconcile')->everyMinute()->withoutOverlapping();

// Advertising data from Meta (and later platforms) refreshes every 20 minutes.
// The command only queues jobs; the queue workers do the API work, so one slow
// or broken brand never delays the rest.
// cron() rather than a helper: this Laravel has everyFifteenMinutes and
// everyThirtyMinutes but no twenty-minute equivalent.
Schedule::command('platforms:sync')->cron('*/20 * * * *')->withoutOverlapping();
