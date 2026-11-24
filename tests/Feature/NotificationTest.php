<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeResident(): User
    {
        return User::factory()->create(['role' => 'resident']);
    }

    private function giveNotification(User $user, string $title = 'Test'): void
    {
        $user->notifications()->create([
            'id'   => (string) Str::uuid(),
            'type' => 'App\Notifications\PaymentReviewed',
            'data' => [
                'type'    => 'payment.approved',
                'title'   => $title,
                'message' => 'Test message',
            ],
        ]);
    }

    public function test_user_lists_only_own_notifications(): void
    {
        $resident = $this->makeResident();
        $other    = $this->makeResident();

        $this->giveNotification($resident);
        $this->giveNotification($other);

        Sanctum::actingAs($resident);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unread_count_and_mark_read(): void
    {
        $resident = $this->makeResident();
        $this->giveNotification($resident);

        Sanctum::actingAs($resident);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);

        $id = $resident->notifications()->first()->id;

        $this->patchJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('id', $id);

        $this->getJson('/api/v1/notifications/unread-count')
            ->assertJson(['count' => 0]);
    }

    public function test_cannot_mark_another_users_notification(): void
    {
        $a = $this->makeResident();
        $b = $this->makeResident();
        $this->giveNotification($a);

        $id = $a->notifications()->first()->id;

        Sanctum::actingAs($b);

        $this->patchJson("/api/v1/notifications/{$id}/read")
            ->assertNotFound();

        $this->assertNull($a->notifications()->first()->read_at);
    }

    public function test_mark_all_as_read(): void
    {
        $resident = $this->makeResident();
        $this->giveNotification($resident, 'One');
        $this->giveNotification($resident, 'Two');

        Sanctum::actingAs($resident);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJson(['count' => 0]);

        $this->assertSame(0, $resident->unreadNotifications()->count());
    }

    public function test_unread_filter_hides_read_notifications(): void
    {
        $resident = $this->makeResident();
        $this->giveNotification($resident, 'Read one');
        $this->giveNotification($resident, 'Unread one');

        $resident->notifications()->first()->markAsRead();

        Sanctum::actingAs($resident);

        $this->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_guest_cannot_access_notifications(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }
}