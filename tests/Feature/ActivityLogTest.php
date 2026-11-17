<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_and_failed_logins_are_logged(): void
    {
        $user = User::factory()->create(['role' => UserRole::Resident]);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();

        $failed = ActivityLog::where('action', 'security.login_failed')->first();
        $this->assertSame($user->email, $failed->meta['email']);
        $this->assertArrayNotHasKey('password', $failed->meta);

        $this->assertDatabaseHas('activity_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_approving_a_payment_is_logged_with_the_committee_member_as_actor(): void
    {
        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $unit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $invoice = Invoice::create([
            'unit_id' => $unit->id, 'period' => '2026-11-01', 'amount' => 150,
            'due_date' => '2026-11-15', 'status' => InvoiceStatus::Unpaid,
        ]);
        $resident = User::factory()->create(['role' => UserRole::Resident]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'paid_by' => $resident->id,
            'method' => PaymentMethod::Transfer, 'amount' => 150, 'status' => PaymentStatus::Pending,
        ]);
        $committee = User::factory()->create(['role' => UserRole::Committee]);

        Sanctum::actingAs($committee);
        $this->postJson("/api/v1/payments/{$payment->id}/approve")->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'payment.approved',
            'user_id' => $committee->id,
            'subject_id' => $payment->id,
        ]);
    }

    public function test_a_forged_billplz_callback_is_logged_as_a_security_event(): void
    {
        config(['services.billplz.x_signature_key' => 'test-secret']);

        $this->postJson('/api/webhooks/billplz', ['id' => 'bill123', 'x_signature' => 'forged'])->assertForbidden();

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'security.billplz_invalid_signature',
            'user_id' => null,
        ]);
    }

    public function test_only_admin_can_read_the_log(): void
    {
        foreach ([UserRole::Resident, UserRole::Committee, UserRole::Security] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson('/api/v1/activity-logs')->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $this->getJson('/api/v1/activity-logs')->assertOk();
    }

    public function test_the_log_can_be_filtered_by_category(): void
    {
        ActivityLog::record('payment.approved', 'Payment approved');
        ActivityLog::record('auth.login', 'Signed in');

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->getJson('/api/v1/activity-logs?category=payment')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'payment.approved');
    }

    public function test_entries_older_than_90_days_are_pruned(): void
    {
        ActivityLog::record('auth.login', 'Old entry');
        ActivityLog::record('auth.login', 'Recent entry');
        ActivityLog::where('description', 'Old entry')->update(['created_at' => now()->subDays(100)]);

        $this->artisan('model:prune')->assertSuccessful();

        $this->assertDatabaseMissing('activity_logs', ['description' => 'Old entry']);
        $this->assertDatabaseHas('activity_logs', ['description' => 'Recent entry']);
    }
}