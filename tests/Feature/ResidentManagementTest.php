<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResidentManagementTest extends TestCase
{
    use RefreshDatabase;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $this->unit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Committee]));
    }

    private function addResident(array $overrides = [])
    {
        return $this->postJson("/api/v1/units/{$this->unit->id}/residents", array_merge([
            'name' => 'Aina',
            'email' => 'aina@example.test',
            'password' => 'password123',
            'relationship' => 'tenant',
        ], $overrides));
    }

    private function makeInvoice(): Invoice
    {
        return Invoice::create([
            'unit_id' => $this->unit->id,
            'period' => '2026-11-01',
            'amount' => 150,
            'due_date' => '2026-11-15',
            'status' => InvoiceStatus::Unpaid,
        ]);
    }

    public function test_a_new_resident_can_log_in_and_sees_the_units_invoices(): void
    {
        $this->makeInvoice();

        $this->addResident()->assertCreated()->assertJsonPath('data.is_current', true);

        $this->postJson('/api/v1/login', ['email' => 'aina@example.test', 'password' => 'password123'])
            ->assertOk();

        Sanctum::actingAs(User::where('email', 'aina@example.test')->first());
        $this->getJson('/api/v1/invoices')->assertJsonCount(1, 'data');
    }

    public function test_an_existing_resident_is_linked_not_duplicated(): void
    {
        User::factory()->create(['role' => UserRole::Resident, 'email' => 'aina@example.test']);

        $this->addResident(['name' => null, 'password' => null])->assertCreated();

        $this->assertDatabaseCount('users', 2);
    }

    public function test_a_new_resident_needs_a_name_and_password(): void
    {
        $this->addResident(['name' => null, 'password' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'password']);
    }

    public function test_a_non_resident_email_is_refused(): void
    {
        User::factory()->create(['role' => UserRole::Security, 'email' => 'guard@example.test']);

        $this->addResident(['email' => 'guard@example.test'])->assertUnprocessable();
    }

    public function test_the_same_person_cannot_be_added_twice_to_one_unit(): void
    {
        $this->addResident()->assertCreated();

        $this->addResident(['name' => null, 'password' => null])->assertStatus(409);
    }

    public function test_the_database_itself_refuses_a_duplicate_active_residency(): void
    {
        $user = User::factory()->create(['role' => UserRole::Resident]);
        $row = ['user_id' => $user->id, 'unit_id' => $this->unit->id, 'relationship' => 'owner'];

        Residency::create($row);

        $this->expectException(QueryException::class);
        Residency::create($row);
    }

    public function test_a_resident_cannot_manage_residents(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Resident]));

        $this->addResident()->assertForbidden();
    }

    public function test_moving_out_ends_access_to_the_units_invoices(): void
    {
        $this->makeInvoice();
        $residencyId = $this->addResident()->json('data.id');
        $resident = User::where('email', 'aina@example.test')->first();

        $this->patchJson("/api/v1/residencies/{$residencyId}/move-out")
            ->assertOk()
            ->assertJsonPath('data.is_current', false);

        Sanctum::actingAs($resident);
        $this->getJson('/api/v1/invoices')->assertJsonCount(0, 'data');
    }

    public function test_moving_out_twice_is_refused(): void
    {
        $residencyId = $this->addResident()->json('data.id');

        $this->patchJson("/api/v1/residencies/{$residencyId}/move-out")->assertOk();
        $this->patchJson("/api/v1/residencies/{$residencyId}/move-out")->assertStatus(409);
    }

    public function test_listing_shows_only_current_residents_unless_asked(): void
    {
        $residencyId = $this->addResident()->json('data.id');
        $this->addResident(['name' => 'Budi', 'email' => 'budi@example.test'])->assertCreated();
        $this->patchJson("/api/v1/residencies/{$residencyId}/move-out")->assertOk();

        $this->getJson("/api/v1/units/{$this->unit->id}/residents")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/units/{$this->unit->id}/residents?include_past=1")->assertJsonCount(2, 'data');
    }
}