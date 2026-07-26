<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'portal:mark-overdue-invoices';
    protected $description = 'Flips non-terminal, non-fully-paid invoices to Overdue once their due date has passed';

    public function handle(): int
    {
        $count = Invoice::whereNotIn('status', array_merge(Invoice::$terminalStatuses, [Invoice::STATUS_PAID, Invoice::STATUS_OVERDUE]))
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => Invoice::STATUS_OVERDUE]);

        $this->info("Marked {$count} invoice(s) as Overdue.");

        return self::SUCCESS;
    }
}
