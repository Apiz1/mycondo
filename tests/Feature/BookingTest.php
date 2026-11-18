<?php

namespace Tests\Feature;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Facility;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private Facility $hall;

    protected function setUp(): void
    {
        parent::setUp();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $unit = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $unit->id, 'relationship' => 'owner']);

        $this->hall = Facility::create(['name' => 'Function Hall']);

        Sanctum::actingAs($this->resident);
    }

    private function book(array $overrides = [], ?Facility $facility = null)
    {
        $facility ??= $this->hall;

        return $this->postJson("/api/v1/facilities/{$facility->id}/bookings", array_merge([
            'date' => now()->addDays(2)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '12:00',
        ], $overrides));
    }

    public function test_resident_can_book_an_open_slot(): void
    {
        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.facility.name', 'Function Hall');
    }

    public function test_a_facility_that_needs_approval_creates_a_pending_booking(): void
    {
        $court = Facility::create(['name' => 'Court', 'needs_approval' => true]);

        $this->book([], $court)->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_overlapping_bookings_are_refused(): void
    {
        $this->book()->assertCreated();

        $this->book(['start_time' => '11:00', 'end_time' => '13:00'])->assertStatus(409);

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_back_to_back_bookings_are_allowed(): void
    {
        $this->book(['start_time' => '10:00', 'end_time' => '12:00'])->assertCreated();
        $this->book(['start_time' => '12:00', 'end_time' => '14:00'])->assertCreated();
    }

    public function test_a_cancelled_booking_frees_the_slot(): void
    {
        $id = $this->book()->assertCreated()->json('data.id');

        Booking::whereKey($id)->update(['status' => BookingStatus::Cancelled->value]);

        $this->book()->assertCreated();
    }

    public function test_the_same_time_at_another_facility_is_fine(): void
    {
        $court = Facility::create(['name' => 'Court']);

        $this->book()->assertCreated();
        $this->book([], $court)->assertCreated();
    }

    public function test_bookings_outside_opening_hours_are_refused(): void
    {
        $this->book(['start_time' => '06:00', 'end_time' => '07:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');
    }

    public function test_bookings_longer_than_the_maximum_are_refused(): void
    {
        $this->book(['start_time' => '10:00', 'end_time' => '16:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    }

    public function test_bookings_in_the_past_are_refused(): void
    {
        $this->book(['date' => now()->subDay()->format('Y-m-d')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_slots_must_start_on_the_half_hour(): void
    {
        $this->book(['start_time' => '10:15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_time');
    }

    public function test_a_resident_who_has_moved_out_cannot_book(): void
    {
        Residency::where('user_id', $this->resident->id)->update(['moved_out_at' => today()]);

        $this->book()->assertForbidden();
    }

    public function test_committee_members_cannot_book_as_residents(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Committee]));

        $this->book()->assertForbidden();
    }

    public function test_availability_shows_booked_times_without_names(): void
    {
        $this->book()->assertCreated();
        $date = now()->addDays(2)->format('Y-m-d');

        $this->getJson("/api/v1/facilities/{$this->hall->id}/availability?date={$date}")
            ->assertOk()
            ->assertJsonCount(1, 'booked')
            ->assertJsonPath('booked.0.start_at', '10:00')
            ->assertJsonPath('booked.0.end_at', '12:00');
    }

    public function test_the_database_itself_refuses_overlapping_bookings(): void
    {
        $row = [
            'facility_id' => $this->hall->id,
            'user_id' => $this->resident->id,
            'start_at' => '2026-12-01 10:00:00',
            'end_at' => '2026-12-01 12:00:00',
            'status' => BookingStatus::Approved,
        ];

        Booking::create($row);

        $this->expectException(QueryException::class);
        Booking::create(array_merge($row, ['start_at' => '2026-12-01 11:00:00', 'end_at' => '2026-12-01 13:00:00']));
    }

    public function test_committee_can_create_a_facility_and_residents_only_see_active_ones(): void
    {
        Facility::create(['name' => 'Closed Gym', 'is_active' => false]);

        $this->getJson('/api/v1/facilities')->assertOk()->assertJsonCount(1, 'data');

        $payload = [
            'name' => 'Tennis Court',
            'open_time' => '07:00',
            'close_time' => '22:00',
            'max_duration_minutes' => 120,
            'needs_approval' => false,
        ];

        $this->postJson('/api/v1/facilities', $payload)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => UserRole::Committee]));

        $this->postJson('/api/v1/facilities', $payload)->assertCreated();
        $this->getJson('/api/v1/facilities')->assertJsonCount(3, 'data');
    }
}