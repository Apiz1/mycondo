<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;

#[Signature('invoices:mark-overdue')]
#[Description('Command description')]
class MarkOverdueInvoices extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = Invoice::where('status', InvoiceStatus::Unpaid->value)
            ->whereDate('due_date', '<', today())
            ->update(['status' => InvoiceStatus::Overdue->value]);

        $this->info("Marked {$count} invoice(s) as overdue.");

        return self::SUCCESS;
    }
}
