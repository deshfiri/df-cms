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

// Settle abandoned audio calls. Runs every minute — a "ringing" row that never
// resolves blocks both participants from placing any further call.
Schedule::command('calls:reconcile')->everyMinute()->withoutOverlapping();
