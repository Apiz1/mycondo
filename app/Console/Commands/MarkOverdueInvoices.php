<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Notifications\InvoiceOverdue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('invoices:mark-overdue')]
#[Description('Mark past-due unpaid invoices as overdue and notify the unit residents')]
class MarkOverdueInvoices extends Command
{
    public function handle(): int
    {
        $count = 0;

        Invoice::with('unit.activeResidents')
            ->where('status', InvoiceStatus::Unpaid->value)
            ->whereDate('due_date', '<', today())
            ->chunkById(100, function ($invoices) use (&$count) {
                foreach ($invoices as $invoice) {
                    DB::transaction(function () use ($invoice) {
                        $invoice->update(['status' => InvoiceStatus::Overdue->value]);

                        foreach ($invoice->unit->activeResidents as $resident) {
                            $resident->notify(new InvoiceOverdue($invoice));
                        }
                    });

                    $count++;
                }
            });

        $this->info("Marked {$count} invoice(s) as overdue.");

        return self::SUCCESS;
    }
}