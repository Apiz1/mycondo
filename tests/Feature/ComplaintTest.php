<?php

namespace Tests\Feature;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintStatus;
use App\Enums\UserRole;
use App\Models\Complaint;
use App\Models\Residency;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ComplaintStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;
    private User $neighbour;
    private User $committee;
    private Unit $unitOne;
    private Unit $unitTwo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

        $type = UnitType::create(['name' => 'Standard', 'monthly_fee' => 150]);
        $this->unitOne = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '1']);
        $this->unitTwo = Unit::create(['unit_type_id' => $type->id, 'block' => 'A', 'unit_number' => '2']);

        $this->resident = User::factory()->create(['role' => UserRole::Resident]);
        $this->neighbour = User::factory()->create(['role' => UserRole::Resident]);
        $this->committee = User::factory()->create(['role' => UserRole::Committee]);

        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $this->unitOne->id, 'relationship' => 'owner']);
        Residency::create(['user_id' => $this->neighbour->id, 'unit_id' => $this->unitTwo->id, 'relationship' => 'owner']);
    }

    private function makeComplaint(User $user, Unit $unit, array $overrides = []): Complaint
    {
        return Complaint::create(array_merge([
            'unit_id' => $unit->id,
            'user_id' => $user->id,
            'category' => ComplaintCategory::Maintenance,
            'title' => 'Broken light',
            'description' => 'The corridor light is out',
            'status' => ComplaintStatus::Open,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'maintenance',
            'title' => 'Leaking pipe',
            'description' => 'Water under the kitchen sink',
        ], $overrides);
    }

    public function test_a_resident_can_file_a_complaint_for_their_own_unit(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/complaints', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.unit.id', $this->unitOne->id)
            ->assertJsonPath('data.has_photo', false);
    }

    public function test_a_photo_is_stored_privately_and_only_the_units_residents_can_open_it(): void
    {
        Sanctum::actingAs($this->resident);

        $id = $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/complaints', $this->payload(['photo' => UploadedFile::fake()->image('leak.jpg')]))
            ->assertCreated()
            ->assertJsonPath('data.has_photo', true)
            ->json('data.id');

        $this->getJson("/api/v1/complaints/{$id}/photo")->assertOk();

        Sanctum::actingAs($this->neighbour);
        $this->getJson("/api/v1/complaints/{$id}/photo")->assertForbidden();
    }

    public function test_a_resident_cannot_file_a_complaint_under_another_unit(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/complaints', $this->payload(['unit_id' => $this->unitTwo->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_someone_in_two_units_must_say_which_one(): void
    {
        Residency::create(['user_id' => $this->resident->id, 'unit_id' => $this->unitTwo->id, 'relationship' => 'tenant']);
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/complaints', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unit_id');

        $this->postJson('/api/v1/complaints', $this->payload(['unit_id' => $this->unitTwo->id]))
            ->assertCreated();
    }

    public function test_a_resident_who_has_moved_out_cannot_file_complaints(): void
    {
        Residency::where('user_id', $this->resident->id)->update(['moved_out_at' => today()]);
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/complaints', $this->payload())->assertForbidden();
    }

    public function test_an_unknown_category_is_refused(): void
    {
        Sanctum::actingAs($this->resident);

        $this->postJson('/api/v1/complaints', $this->payload(['category' => 'nonsense']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_residents_see_only_their_units_complaints_and_the_committee_sees_all(): void
    {
        $this->makeComplaint($this->resident, $this->unitOne);
        $this->makeComplaint($this->neighbour, $this->unitTwo);

        Sanctum::actingAs($this->resident);
        $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->committee);
        $this->getJson('/api/v1/complaints')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_resident_cannot_open_a_neighbours_complaint(): void
    {
        $complaint = $this->makeComplaint($this->neighbour, $this->unitTwo);
        Sanctum::actingAs($this->resident);

        $this->getJson("/api/v1/complaints/{$complaint->id}")->assertForbidden();
    }

    public function test_the_committee_moves_a_complaint_through_its_statuses(): void
    {
        Notification::fake();

        $complaint = $this->makeComplaint($this->resident, $this->unitOne);
        Sanctum::actingAs($this->committee);

        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'resolved'])->assertOk();
        $this->assertNotNull($complaint->fresh()->resolved_at);

        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'open'])->assertOk();
        $this->assertNull($complaint->fresh()->resolved_at);

        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'closed'])->assertOk();
        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'open'])->assertStatus(409);

        $this->assertDatabaseHas('activity_logs', ['action' => 'complaint.status_changed', 'user_id' => $this->committee->id]);

        // 4 real changes notify the person who filed it; the refused 409 sends nothing
        Notification::assertSentToTimes($this->resident, ComplaintStatusChanged::class, 4);
        Notification::assertNotSentTo($this->committee, ComplaintStatusChanged::class);
    }

    public function test_setting_the_same_status_does_not_notify(): void
    {
        Notification::fake();

        $complaint = $this->makeComplaint($this->resident, $this->unitOne);
        Sanctum::actingAs($this->committee);

        // The complaint is already open, so "open" is not a change
        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'open'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_resident_cannot_change_a_complaints_status(): void
    {
        $complaint = $this->makeComplaint($this->resident, $this->unitOne);
        Sanctum::actingAs($this->resident);

        $this->patchJson("/api/v1/complaints/{$complaint->id}/status", ['status' => 'resolved'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this.');
    }

    public function test_the_unit_and_the_committee_can_comment_but_neighbours_cannot(): void
    {
        $complaint = $this->makeComplaint($this->resident, $this->unitOne);

        Sanctum::actingAs($this->resident);
        $this->postJson("/api/v1/complaints/{$complaint->id}/comments", ['body' => 'Still leaking'])->assertCreated();

        Sanctum::actingAs($this->committee);
        $this->postJson("/api/v1/complaints/{$complaint->id}/comments", ['body' => 'Plumber booked'])->assertCreated();

        $this->getJson("/api/v1/complaints/{$complaint->id}")->assertJsonCount(2, 'data.comments');

        Sanctum::actingAs($this->neighbour);
        $this->postJson("/api/v1/complaints/{$complaint->id}/comments", ['body' => 'Me too'])->assertForbidden();
    }

    public function test_a_closed_complaint_refuses_new_comments_and_empty_comments_are_invalid(): void
    {
        $open = $this->makeComplaint($this->resident, $this->unitOne);
        $closed = $this->makeComplaint($this->resident, $this->unitOne, ['status' => ComplaintStatus::Closed]);
        Sanctum::actingAs($this->resident);

        $this->postJson("/api/v1/complaints/{$closed->id}/comments", ['body' => 'Hello?'])->assertStatus(409);
        $this->postJson("/api/v1/complaints/{$open->id}/comments", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }
}