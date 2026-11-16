<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function makeUnit(string $number = '1'): Unit
    {
        $type = UnitType::firstOrCreate(['name' => 'Standard'], ['monthly_fee' => 150]);

        return Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => $number]);
    }

    public function test_generate_creates_one_invoice_per_unit_and_is_safe_to_repeat(): void
    {
        $this->makeUnit('1');
        $this->makeUnit('2');

        $this->artisan('invoices:generate --month=2026-11')->assertSuccessful();
        $this->artisan('invoices:generate --month=2026-11')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 2);
    }

    public function test_generate_copies_the_current_fee_onto_the_invoice(): void
    {
        $unit = $this->makeUnit();

        $this->artisan('invoices:generate --month=2026-11');
        $unit->unitType->update(['monthly_fee' => 999]);

        $this->assertSame('150.00', Invoice::first()->amount);
    }

    public function test_mark_overdue_only_changes_unpaid_invoices_past_their_due_date(): void
    {
        $unit = $this->makeUnit();

        $late = Invoice::create([
            'unit_id' => $unit->id, 'period' => '2026-09-01', 'amount' => 150,
            'due_date' => today()->subDay(), 'status' => InvoiceStatus::Unpaid,
        ]);
        $notYetDue = Invoice::create([
            'unit_id' => $unit->id, 'period' => '2026-10-01', 'amount' => 150,
            'due_date' => today()->addDay(), 'status' => InvoiceStatus::Unpaid,
        ]);
        $alreadyPaid = Invoice::create([
            'unit_id' => $unit->id, 'period' => '2026-08-01', 'amount' => 150,
            'due_date' => today()->subDays(30), 'status' => InvoiceStatus::Paid,
        ]);

        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Overdue, $late->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $notYetDue->fresh()->status);
        $this->assertSame(InvoiceStatus::Paid, $alreadyPaid->fresh()->status);
    }
}
