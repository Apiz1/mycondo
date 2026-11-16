<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Unit;
use Carbon\Carbon;

class GenerateMonthlyInvoices extends Command
{
    protected $signature = 'invoices:generate {--month= : Month to bill as YYYY-MM (default: this month)}';

    protected $description = 'Create maintenance fee invoices for every unit for one month';

    public function handle(): int
    {
        $period = $this->option('month')
            ? Carbon::createFromFormat('!Y-m', $this->option('month'))
            : now()->startOfMonth();

        $dueDate = $period->copy()->addDays(14);
        $created = 0;

        Unit::with('unitType')->each(function (Unit $unit) use ($period, $dueDate, &$created) {
            $invoice = Invoice::firstOrCreate(
                ['unit_id' => $unit->id, 'period' => $period->toDateString()],
                [
                    'amount' => $unit->unitType->monthly_fee,
                    'due_date' => $dueDate->toDateString(),
                    'status' => InvoiceStatus::Unpaid,
                ],
            );

            if ($invoice->wasRecentlyCreated) {
                $created++;
            }
        });

        $this->info("Created {$created} invoice(s) for {$period->format('F Y')}.");

        return self::SUCCESS;
    }
}
