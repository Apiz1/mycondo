<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitorTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $neighbour;
    private User $committee;
    private User $security;
    private Unit $unitOne;
    private Unit $unitTwo;

    protected function setUp(): void
    {
        parent::setUp();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $this->unitOne = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $this->unitTwo = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '2']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        $this->neighbour = User::factory()->create(['role' => UserRole::Resident]);
        $this->committee = User::factory()->create(['role' => UserRole::Committee]);
        $this->security = User::factory()->create(['role' => UserRole::Security]);

        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $this->unitOne->id, 'relationship' => 'owner']);
        Residency::create(['user_id' => $this->neighbour->id, 'unit_id' => $this->unitTwo->id, 'relationship' => 'owner']);
    }

    private function makeVisitor(Unit $unit, User $registrar, array $overrides = []): Visitor
    {
        return Visitor::create(array_merge([
            'unit_id' => $unit->id,
            'user_id' => $registrar->id,
            'name' => 'Ali',
            'visit_date' => today(),
            'code' => Visitor::generateCode(),
            'status' => VisitorStatus::Pending,
        ], $overrides));
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/v1/visitors', array_merge([
            'name' => 'Ali',
            'vehicle_plate' => 'pbc 1234',
            'visit_date' => today()->toDateString(),
        ], $overrides));
    }

    public function test_a_resident_can_register_a_visitor_and_gets_a_code(): void
    {
        Sanctum::actingAs($this->resident);

        $response = $this->register()
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.vehicle_plate', 'PBC 1234')
            ->assertJsonPath('data.unit.label', 'A-1');

        $this->assertSame(10, strlen($response->json('data.code')));
    }

    public function test_only_residents_can_see_the_pass_code(): void
    {
        $this->makeVisitor($this->unitOne, $this->resident);

        Sanctum::actingAs($this->resident);
        $this->getJson('/api/v1/visitors')->assertJsonPath('data.0.name', 'Ali')->assertJsonStructure(['data' => [['code']]]);

        Sanctum::actingAs($this->committee);
        $this->getJson('/api/v1/visitors')->assertOk()->assertJsonMissingPath('data.0.code');
    }

    public function test_a_resident_cannot_register_a_visitor_for_another_unit(): void
    {
        Sanctum::actingAs($this->resident);

        $this->register(['unit_id' => $this->unitTwo->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_the_visit_date_must_be_today_or_within_the_next_30_days(): void
    {
        Sanctum::actingAs($this->resident);

        $this->register(['visit_date' => today()->subDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visit_date');

        $this->register(['visit_date' => today()->addDays(31)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('visit_date');

        $this->register(['visit_date' => today()->addDays(30)->toDateString()])->assertCreated();
    }

    public function test_a_unit_cannot_exceed_ten_passes_a_day_and_cancelled_passes_do_not_count(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeVisitor($this->unitOne, $this->resident);
        }

        Sanctum::actingAs($this->resident);

        $this->register()->assertUnprocessable()->assertJsonValidationErrors('visit_date');
        $this->register(['visit_date' => today()->addDay()->toDateString()])->assertCreated();

        Visitor::first()->update(['status' => VisitorStatus::Cancelled]);
        $this->register()->assertCreated();
    }

    public function test_a_resident_who_has_moved_out_cannot_register_visitors(): void
    {
        Residency::where('user_id', $this->resident->id)->update(['moved_out_at' => today()]);
        Sanctum::actingAs($this->resident);

        $this->register()->assertForbidden();
    }

    public function test_listing_is_scoped_by_role(): void
    {
        $this->makeVisitor($this->unitOne, $this->resident);
        $this->makeVisitor($this->unitTwo, $this->neighbour);

        Sanctum::actingAs($this->resident);
        $this->getJson('/api/v1/visitors')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->committee);
        $this->getJson('/api/v1/visitors')->assertOk()->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->security);
        $this->getJson('/api/v1/visitors')->assertForbidden();
    }

    public function test_a_resident_can_cancel_their_units_pass_but_a_neighbour_cannot(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);

        Sanctum::actingAs($this->neighbour);
        $this->postJson("/api/v1/visitors/{$visitor->id}/cancel")->assertForbidden();

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/visitors/{$visitor->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_pass_that_has_been_used_cannot_be_cancelled(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident, ['status' => VisitorStatus::CheckedIn]);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/visitors/{$visitor->id}/cancel")->assertStatus(409);
    }

    public function test_security_can_check_a_visitor_in(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_in')
            ->assertJsonPath('data.name', 'Ali')
            ->assertJsonPath('data.unit.label', 'A-1');

        $this->assertDatabaseHas('visitors', ['id' => $visitor->id, 'checked_in_by' => $this->security->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'visitor.checked_in', 'user_id' => $this->security->id]);
    }

    public function test_the_code_works_in_lower_case_with_stray_spaces(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => ' '.strtolower($visitor->code).' '])->assertOk();
    }

    public function test_a_pass_works_only_once(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])->assertOk();
        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])->assertStatus(409);
    }

    public function test_an_unknown_code_is_refused_and_logged(): void
    {
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => 'ZZZZZZZZZZ'])->assertNotFound();

        $this->assertDatabaseHas('activity_logs', ['action' => 'security.gate_unknown_code']);
    }

    public function test_a_pass_for_another_day_is_refused(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident, ['visit_date' => today()->addDay()]);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])->assertStatus(409);
        $this->assertSame(VisitorStatus::Pending, $visitor->fresh()->status);
    }

    public function test_a_cancelled_pass_is_refused_at_the_gate(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident, ['status' => VisitorStatus::Cancelled]);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])->assertStatus(409);
    }

    public function test_a_visitor_must_be_checked_in_before_they_can_be_checked_out(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);
        Sanctum::actingAs($this->security);

        $this->postJson('/api/v1/gate/check-out', ['code' => $visitor->code])->assertStatus(409);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])->assertOk();
        $this->postJson('/api/v1/gate/check-out', ['code' => $visitor->code])
            ->assertOk()
            ->assertJsonPath('data.status', 'checked_out');

        $this->postJson('/api/v1/gate/check-out', ['code' => $visitor->code])->assertStatus(409);
    }

    public function test_residents_cannot_use_the_gate(): void
    {
        $visitor = $this->makeVisitor($this->unitOne, $this->resident);
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/gate/check-in', ['code' => $visitor->code])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this.');
    }

    public function test_the_expected_list_shows_todays_visitors_without_their_codes(): void
    {
        $this->makeVisitor($this->unitOne, $this->resident, ['name' => 'Ali']);
        $this->makeVisitor($this->unitOne, $this->resident, ['name' => 'Budi', 'status' => VisitorStatus::CheckedIn]);
        $this->makeVisitor($this->unitOne, $this->resident, ['name' => 'Cili', 'status' => VisitorStatus::Cancelled]);
        $this->makeVisitor($this->unitTwo, $this->neighbour, ['name' => 'Dina', 'visit_date' => today()->addDay()]);

        Sanctum::actingAs($this->security);

        $this->getJson('/api/v1/gate/expected')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.code');
    }

    public function test_the_database_refuses_a_duplicate_code(): void
    {
        $first = $this->makeVisitor($this->unitOne, $this->resident);

        $this->expectException(QueryException::class);
        $this->makeVisitor($this->unitOne, $this->resident, ['code' => $first->code]);
    }
}