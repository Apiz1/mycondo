<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'status' => 'active']);
    }

    private function makeUser(UserRole $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role, 'status' => 'active', 'password' => 'password',
        ], $overrides));
    }

    private function login(User $user, string $password = 'password')
    {
        return $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password]);
    }

    public function test_admin_lists_users_and_can_filter_by_role(): void
    {
        $this->makeUser(UserRole::Security);
        $this->makeUser(UserRole::Committee);
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/users?role=security')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_admin_can_manage_users(): void
    {
        foreach ([UserRole::Committee, UserRole::Resident, UserRole::Security] as $role) {
            Sanctum::actingAs($this->makeUser($role));

            $this->getJson('/api/v1/users')->assertForbidden();
            $this->postJson('/api/v1/users', [])->assertForbidden();
        }
    }

    public function test_admin_creates_a_staff_account_that_can_log_in(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Guard One', 'email' => 'guard@mycondo.test',
            'role' => 'security', 'password' => 'secret-pass-1',
        ])->assertCreated()->assertJsonPath('data.role', 'security');

        $this->assertDatabaseHas('users', ['email' => 'guard@mycondo.test', 'role' => 'security', 'status' => 'active']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.created', 'user_id' => $this->admin->id]);

        $this->postJson('/api/v1/login', ['email' => 'guard@mycondo.test', 'password' => 'secret-pass-1'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_admin_cannot_create_residents_or_admins_here(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['resident', 'admin'] as $role) {
            $this->postJson('/api/v1/users', [
                'name' => 'X', 'email' => "{$role}@mycondo.test", 'role' => $role, 'password' => 'secret-pass-1',
            ])->assertUnprocessable()->assertJsonValidationErrors('role');
        }
    }

    public function test_a_duplicate_email_is_refused(): void
    {
        $existing = $this->makeUser(UserRole::Committee);
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Copy', 'email' => $existing->email, 'role' => 'committee', 'password' => 'secret-pass-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_deactivating_blocks_login_and_ends_open_sessions(): void
    {
        $guard = $this->makeUser(UserRole::Security);
        $guard->createToken('test');
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$guard->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertSame(0, $guard->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.deactivated', 'user_id' => $this->admin->id]);

        $this->login($guard)->assertForbidden();
    }

    public function test_an_admin_cannot_deactivate_their_own_account(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$this->admin->id}/deactivate")->assertForbidden();

        $this->assertSame('active', $this->admin->fresh()->status);
    }

    public function test_reactivating_restores_login(): void
    {
        $guard = $this->makeUser(UserRole::Security, ['status' => 'inactive']);
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/v1/users/{$guard->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
        $this->patchJson("/api/v1/users/{$guard->id}/activate")->assertStatus(409);

        $this->login($guard)->assertOk();
    }

    public function test_resetting_a_password_replaces_it_and_ends_sessions(): void
    {
        $guard = $this->makeUser(UserRole::Security);
        $guard->createToken('test');
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/users/{$guard->id}/reset-password", ['password' => 'brand-new-pass'])->assertOk();

        $this->assertSame(0, $guard->tokens()->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'user.password_reset', 'user_id' => $this->admin->id]);

        $this->login($guard, 'password')->assertUnprocessable();
        $this->login($guard, 'brand-new-pass')->assertOk();
    }

    public function test_admin_has_the_committee_powers(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/payments')->assertOk();
        $this->getJson('/api/v1/units')->assertOk();
        $this->postJson('/api/v1/announcements', ['title' => 'Notice', 'body' => 'From the admin'])->assertCreated();
    }
}