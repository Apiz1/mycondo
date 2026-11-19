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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $neighbour;
    private User $committee;
    private Facility $hall;

    protected function setUp(): void
    {
        parent::setUp();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $unitOne = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $unitTwo = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '2']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        $this->neighbour = User::factory()->create(['role' => UserRole::Resident]);
        $this->committee = User::factory()->create(['role' => UserRole::Committee]);

        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $unitOne->id, 'relationship' => 'owner']);
        Residency::create(['user_id' => $this->neighbour->id, 'unit_id' => $unitTwo->id, 'relationship' => 'owner']);

        $this->hall = Facility::create(['name' => 'Function Hall', 'needs_approval' => true]);
    }

    private function makeBooking(User $user, array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'facility_id' => $this->hall->id,
            'user_id' => $user->id,
            'start_at' => now()->addDays(3)->setTime(10, 0),
            'end_at' => now()->addDays(3)->setTime(12, 0),
            'status' => BookingStatus::Pending,
        ], $overrides));
    }

    private function bookSameSlotViaApi()
    {
        return $this->postJson("/api/v1/facilities/{$this->hall->id}/bookings", [
            'date' => now()->addDays(3)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '12:00',
        ]);
    }

    public function test_residents_see_only_their_own_bookings_and_the_committee_sees_all(): void
    {
        $this->makeBooking($this->resident);
        $this->makeBooking($this->neighbour, [
            'start_at' => now()->addDays(3)->setTime(14, 0),
            'end_at' => now()->addDays(3)->setTime(16, 0),
        ]);

        Sanctum::actingAs($this->resident);
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->committee);
        $this->getJson('/api/v1/bookings')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_resident_can_cancel_their_booking_and_the_slot_becomes_free(): void
    {
        $booking = $this->makeBooking($this->resident);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->bookSameSlotViaApi()->assertCreated();
    }

    public function test_a_resident_cannot_cancel_someone_elses_booking(): void
    {
        $booking = $this->makeBooking($this->neighbour);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertForbidden();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_the_committee_can_cancel_any_booking(): void
    {
        $booking = $this->makeBooking($this->resident);
        Sanctum::actingAs($this->committee);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertOk();
    }

    public function test_a_cancelled_booking_cannot_be_cancelled_again(): void
    {
        $booking = $this->makeBooking($this->resident, ['status' => BookingStatus::Cancelled]);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertStatus(409);
    }

    public function test_a_booking_that_has_already_started_cannot_be_cancelled(): void
    {
        $booking = $this->makeBooking($this->resident, [
            'start_at' => now()->subHours(3),
            'end_at' => now()->subHour(),
            'status' => BookingStatus::Approved,
        ]);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertStatus(409);
    }

    public function test_the_committee_can_approve_a_pending_booking(): void
    {
        $booking = $this->makeBooking($this->resident);
        Sanctum::actingAs($this->committee);

        $this->postJson("/api/v1/bookings/{$booking->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'decided_by' => $this->committee->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.approved', 'user_id' => $this->committee->id]);
    }

    public function test_only_pending_bookings_can_be_approved(): void
    {
        $booking = $this->makeBooking($this->resident, ['status' => BookingStatus::Approved]);
        Sanctum::actingAs($this->committee);

        $this->postJson("/api/v1/bookings/{$booking->id}/approve")->assertStatus(409);
    }

    public function test_a_booking_that_has_already_started_cannot_be_approved(): void
    {
        $booking = $this->makeBooking($this->resident, [
            'start_at' => now()->subHours(3),
            'end_at' => now()->subHour(),
        ]);
        Sanctum::actingAs($this->committee);

        $this->postJson("/api/v1/bookings/{$booking->id}/approve")->assertStatus(409);
    }

    public function test_rejection_needs_a_reason_and_frees_the_slot(): void
    {
        $booking = $this->makeBooking($this->resident);
        Sanctum::actingAs($this->committee);

        $this->postJson("/api/v1/bookings/{$booking->id}/reject")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_note');

        $this->postJson("/api/v1/bookings/{$booking->id}/reject", ['review_note' => 'Reserved for the AGM'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($this->resident);
        $this->bookSameSlotViaApi()->assertCreated();
    }

    public function test_a_resident_cannot_approve_bookings(): void
    {
        $booking = $this->makeBooking($this->resident);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/bookings/{$booking->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this.');

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }
}