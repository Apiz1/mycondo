<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillplzPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private Invoice $ownInvoice;
    private Invoice $otherInvoice;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.billplz.key' => 'test-key',
            'services.billplz.collection_id' => 'test-collection',
            'services.billplz.x_signature_key' => 'test-secret',
            'services.billplz.sandbox' => true,
        ]);

        

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $ownUnit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $otherUnit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '2']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $ownUnit->id, 'relationship' => 'owner']);

        $this->ownInvoice = $this->makeInvoice($ownUnit);
        $this->otherInvoice = $this->makeInvoice($otherUnit);
    }

    private function fakeBillplz(): void
    {
        Http::fake([
            '*billplz-sandbox.com/*' => Http::response([
                'id' => 'bill123',
                'url' => 'https://www.billplz-sandbox.com/bills/bill123',
            ], 200),
        ]);
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

    private function pendingBillplzPayment(): Payment
    {
        return Payment::create([
            'invoice_id' => $this->ownInvoice->id,
            'paid_by' => $this->resident->id,
            'method' => PaymentMethod::Billplz,
            'amount' => 150,
            'status' => PaymentStatus::Pending,
            'billplz_bill_id' => 'bill123',
            'billplz_url' => 'https://www.billplz-sandbox.com/bills/bill123',
        ]);
    }

    private function signed(array $params): array
    {
        $pairs = collect($params)->map(fn ($value, $key) => $key.$value)->values()->all();
        usort($pairs, 'strcasecmp');

        $params['x_signature'] = hash_hmac('sha256', implode('|', $pairs), 'test-secret');

        return $params;
    }

    private function callbackPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => 'bill123',
            'collection_id' => 'test-collection',
            'paid' => 'true',
            'state' => 'paid',
            'amount' => '15000',
            'paid_amount' => '15000',
            'paid_at' => '2026-11-17 10:00:00 +0800',
        ], $overrides);
    }

    public function test_resident_can_start_a_billplz_payment(): void
    {
        Sanctum::actingAs($this->resident);

        $this->fakeBillplz();

        $this->postJson("/api/v1/invoices/{$this->ownInvoice->id}/pay/billplz")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_url', 'https://www.billplz-sandbox.com/bills/bill123');

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->ownInvoice->id,
            'billplz_bill_id' => 'bill123',
        ]);

        Http::assertSent(fn ($request) => $request['amount'] == 15000
            && $request['collection_id'] === 'test-collection');
    }

    public function test_starting_twice_returns_the_same_payment_url_without_a_second_bill(): void
    {
        Sanctum::actingAs($this->resident);

        $this->fakeBillplz();

        $this->postJson("/api/v1/invoices/{$this->ownInvoice->id}/pay/billplz")->assertCreated();
        $this->postJson("/api/v1/invoices/{$this->ownInvoice->id}/pay/billplz")
            ->assertOk()
            ->assertJsonPath('data.payment_url', 'https://www.billplz-sandbox.com/bills/bill123');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_resident_cannot_pay_another_units_invoice_online(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/invoices/{$this->otherInvoice->id}/pay/billplz")->assertForbidden();
    }

    public function test_a_gateway_failure_leaves_no_payment_behind(): void
    {
        Http::fake(['*billplz-sandbox.com/*' => Http::response(['error' => 'down'], 500)]);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/invoices/{$this->ownInvoice->id}/pay/billplz")->assertStatus(502);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_valid_callback_marks_the_payment_approved_and_the_invoice_paid(): void
    {
        $payment = $this->pendingBillplzPayment();

        $this->postJson('/api/webhooks/billplz', $this->signed($this->callbackPayload()))->assertOk();

        $this->assertSame(PaymentStatus::Approved, $payment->fresh()->status);
        $this->assertNull($payment->fresh()->verified_by);
        $this->assertSame(InvoiceStatus::Paid, $this->ownInvoice->fresh()->status);
    }

    public function test_a_repeated_callback_changes_nothing(): void
    {
        $payment = $this->pendingBillplzPayment();
        $body = $this->signed($this->callbackPayload());

        $this->postJson('/api/webhooks/billplz', $body)->assertOk();
        $verifiedAt = $payment->fresh()->verified_at;

        $this->postJson('/api/webhooks/billplz', $body)->assertOk();

        $this->assertEquals($verifiedAt, $payment->fresh()->verified_at);
        $this->assertSame(InvoiceStatus::Paid, $this->ownInvoice->fresh()->status);
    }

    public function test_a_forged_callback_is_rejected(): void
    {
        $payment = $this->pendingBillplzPayment();
        $body = $this->callbackPayload();
        $body['x_signature'] = 'not-a-real-signature';

        $this->postJson('/api/webhooks/billplz', $body)->assertForbidden();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $this->ownInvoice->fresh()->status);
    }

    public function test_a_callback_that_is_not_paid_leaves_the_payment_pending(): void
    {
        $payment = $this->pendingBillplzPayment();

        $this->postJson('/api/webhooks/billplz', $this->signed($this->callbackPayload(['paid' => 'false', 'state' => 'due'])))
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_a_callback_with_the_wrong_amount_leaves_the_payment_pending(): void
    {
        $payment = $this->pendingBillplzPayment();

        $this->postJson('/api/webhooks/billplz', $this->signed($this->callbackPayload(['paid_amount' => '100'])))
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $this->ownInvoice->fresh()->status);
    }

    public function test_a_callback_for_an_unknown_bill_is_acknowledged_and_ignored(): void
    {
        $this->postJson('/api/webhooks/billplz', $this->signed($this->callbackPayload(['id' => 'nope'])))->assertOk();

        $this->assertDatabaseCount('payments', 0);
    }
}