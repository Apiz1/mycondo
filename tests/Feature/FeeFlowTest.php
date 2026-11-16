<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeeFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $committee;
    private Invoice $ownInvoice;
    private Invoice $otherInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $ownUnit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $otherUnit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '2']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        $this->committee = User::factory()->create(['role' => UserRole::Committee]);

        Residency::create([
            'user_id' => $this->resident->id,
            'unit_id' => $ownUnit->id,
            'relationship' => 'owner',
        ]);

        $this->ownInvoice = $this->makeInvoice($ownUnit);
        $this->otherInvoice = $this->makeInvoice($otherUnit);
    }

    private function makeInvoice(Unit $unit): Invoice
    {
        return Invoice::create([
            'unit_id' => $unit->id,
            'period' => '2026-11-01',
            'amount' => 150,
            'due_date' => '2026-11-15',
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    private function submitProof(Invoice $invoice, bool $withFile = true)
    {
        $data = $withFile ? ['proof' => UploadedFile::fake()->image('slip.jpg')] : [];

        return $this->withHeaders(['Accept' => 'application/json'])
            ->post("/api/v1/invoices/{$invoice->id}/payments", $data);
    }

    public function test_resident_only_sees_their_own_units_invoices(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson('/api/v1/invoices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->ownInvoice->id);
    }

    public function test_resident_cannot_view_another_units_invoice(): void
    {
        Sanctum::actingAs($this->resident);

        $this->getJson("/api/v1/invoices/{$this->otherInvoice->id}")->assertForbidden();
    }

    public function test_security_cannot_list_invoices(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Security]));

        $this->getJson('/api/v1/invoices')->assertForbidden();
    }

    public function test_resident_can_submit_proof_for_their_own_invoice(): void
    {
        Sanctum::actingAs($this->resident);

        $this->submitProof($this->ownInvoice)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '150.00');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->ownInvoice->id,
            'paid_by' => $this->resident->id,
            'status' => 'pending',
        ]);
    }

    public function test_proof_file_is_required(): void
    {
        Sanctum::actingAs($this->resident);

        $this->submitProof($this->ownInvoice, withFile: false)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof');
    }

    public function test_resident_cannot_pay_another_units_invoice(): void
    {
        Sanctum::actingAs($this->resident);

        $this->submitProof($this->otherInvoice)->assertForbidden();
    }

    public function test_resident_cannot_have_two_pending_payments_for_one_invoice(): void
    {
        Sanctum::actingAs($this->resident);

        $this->submitProof($this->ownInvoice)->assertCreated();
        $this->submitProof($this->ownInvoice)->assertStatus(409);

        $this->assertDatabaseCount('payments', 1);
    }

    public function test_resident_cannot_approve_payments(): void
    {
        Sanctum::actingAs($this->resident);
        $paymentId = $this->submitProof($this->ownInvoice)->json('data.id');

        $this->postJson("/api/v1/payments/{$paymentId}/approve")->assertForbidden();

        $this->assertSame(InvoiceStatus::Unpaid, $this->ownInvoice->fresh()->status);
    }

    public function test_committee_approval_marks_the_invoice_paid(): void
    {
        Sanctum::actingAs($this->resident);
        $paymentId = $this->submitProof($this->ownInvoice)->json('data.id');

        Sanctum::actingAs($this->committee);
        $this->postJson("/api/v1/payments/{$paymentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(InvoiceStatus::Paid, $this->ownInvoice->fresh()->status);
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'verified_by' => $this->committee->id]);
    }

    public function test_a_payment_cannot_be_reviewed_twice(): void
    {
        Sanctum::actingAs($this->resident);
        $paymentId = $this->submitProof($this->ownInvoice)->json('data.id');

        Sanctum::actingAs($this->committee);
        $this->postJson("/api/v1/payments/{$paymentId}/approve")->assertOk();
        $this->postJson("/api/v1/payments/{$paymentId}/approve")->assertStatus(409);
    }

    public function test_rejection_needs_a_reason_and_lets_the_resident_resubmit(): void
    {
        Sanctum::actingAs($this->resident);
        $paymentId = $this->submitProof($this->ownInvoice)->json('data.id');

        Sanctum::actingAs($this->committee);
        $this->postJson("/api/v1/payments/{$paymentId}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_note');

        $this->postJson("/api/v1/payments/{$paymentId}/reject", ['review_note' => 'Amount does not match'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(InvoiceStatus::Unpaid, $this->ownInvoice->fresh()->status);

        Sanctum::actingAs($this->resident);
        $this->submitProof($this->ownInvoice)->assertCreated();
    }
}