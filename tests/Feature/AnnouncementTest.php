<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_every_role_can_read_announcements(): void
    {
        $committee = $this->userWithRole(UserRole::Committee);
        Announcement::create(['author_id' => $committee->id, 'title' => 'Water cut', 'body' => 'Tomorrow 9am']);

        foreach ([UserRole::Resident, UserRole::Committee, UserRole::Security, UserRole::Admin] as $role) {
            Sanctum::actingAs($this->userWithRole($role));

            $this->getJson('/api/v1/announcements')
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_pinned_announcements_come_first(): void
    {
        $committee = $this->userWithRole(UserRole::Committee);
        Announcement::create(['author_id' => $committee->id, 'title' => 'Pinned', 'body' => 'x', 'is_pinned' => true]);
        Announcement::create(['author_id' => $committee->id, 'title' => 'Newer but not pinned', 'body' => 'x']);

        Sanctum::actingAs($this->userWithRole(UserRole::Resident));

        $this->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Pinned');
    }

    public function test_resident_cannot_post_announcements(): void
    {
        Sanctum::actingAs($this->userWithRole(UserRole::Resident));

        $this->postJson('/api/v1/announcements', ['title' => 'Hi', 'body' => 'There'])
            ->assertForbidden();

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_committee_can_post_and_the_author_comes_from_the_login(): void
    {
        $committee = $this->userWithRole(UserRole::Committee);
        Sanctum::actingAs($committee);

        $this->postJson('/api/v1/announcements', [
            'title' => 'AGM',
            'body' => 'Saturday 10am at the hall',
            'author_id' => 999,
        ])->assertCreated();

        $this->assertDatabaseHas('announcements', ['title' => 'AGM', 'author_id' => $committee->id]);
    }

    public function test_title_and_body_are_required(): void
    {
        Sanctum::actingAs($this->userWithRole(UserRole::Committee));

        $this->postJson('/api/v1/announcements', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_committee_can_delete_an_announcement(): void
    {
        $committee = $this->userWithRole(UserRole::Committee);
        $announcement = Announcement::create(['author_id' => $committee->id, 'title' => 'Old', 'body' => 'x']);

        Sanctum::actingAs($committee);

        $this->deleteJson("/api/v1/announcements/{$announcement->id}")->assertNoContent();
        $this->assertDatabaseCount('announcements', 0);
    }
}