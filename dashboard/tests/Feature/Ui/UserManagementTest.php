<?php

namespace Tests\Feature\Ui;

use App\Livewire\Settings\UserForm;
use App\Livewire\Settings\Users;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_only_admins_can_open_the_users_page(): void
    {
        $target = User::factory()->create(['name' => 'Target Operator', 'role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);
        $viewer = User::factory()->create();

        $this->get('/settings/users')->assertRedirect('/login');

        $this->actingAs($operator)->get('/settings/users')->assertForbidden();
        $this->actingAs($viewer)->get('/settings/users')->assertForbidden();

        $this->actingAs($admin)
            ->get('/settings/users')
            ->assertOk()
            ->assertSee('Users')
            ->assertSee($target->name)
            ->assertSee($target->email)
            ->assertSee('Add user');
    }

    public function test_an_admin_can_create_a_user_with_a_temporary_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(UserForm::class)
            ->set('name', 'New Operator')
            ->set('email', 'new.operator@example.com')
            ->set('role', 'operator')
            ->set('isActive', true)
            ->set('password', 'temporary-password-1')
            ->set('passwordConfirmation', 'temporary-password-1')
            ->call('save')
            ->assertHasNoErrors();

        $created = User::query()->where('email', 'new.operator@example.com')->firstOrFail();

        $this->assertSame('New Operator', $created->name);
        $this->assertSame('operator', $created->role->value);
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->must_change_password);
        $this->assertTrue(Hash::check('temporary-password-1', $created->password));

        $audit = AuditEvent::query()->where('action', 'user.created')->firstOrFail();
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame((string) $created->id, $audit->target_id);
    }

    public function test_duplicate_emails_are_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(UserForm::class)
            ->set('name', 'Duplicate')
            ->set('email', 'taken@example.com')
            ->set('role', 'viewer')
            ->set('password', 'temporary-password-1')
            ->set('passwordConfirmation', 'temporary-password-1')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertSame(2, User::query()->count());
    }

    public function test_an_admin_can_change_a_role_and_deactivate_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['userId' => $target->id])
            ->set('role', 'viewer')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertSame('viewer', $target->role->value);
        $this->assertFalse($target->is_active);

        $this->assertTrue(AuditEvent::query()->where('action', 'user.role_changed')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'user.deactivated')->exists());

        $roleAudit = AuditEvent::query()->where('action', 'user.role_changed')->sole();
        $this->assertSame('operator', $roleAudit->meta_json['old_role']);
        $this->assertSame('viewer', $roleAudit->meta_json['new_role']);
    }

    public function test_the_last_active_admin_cannot_be_demoted_deactivated_or_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['userId' => $admin->id])
            ->set('role', 'viewer')
            ->call('save')
            ->assertHasErrors('role');

        Livewire::test(UserForm::class, ['userId' => $admin->id])
            ->set('isActive', false)
            ->call('save')
            ->assertHasErrors('isActive');

        Livewire::test(Users::class)
            ->call('delete', $admin->id)
            ->assertHasErrors('delete');

        $admin->refresh();
        $this->assertSame('admin', $admin->role->value);
        $this->assertTrue($admin->is_active);

        // With a second active admin, the guard releases.
        $second = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Livewire::test(UserForm::class, ['userId' => $admin->id])
            ->set('role', 'viewer')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('viewer', $admin->fresh()->role->value);

        // A third administrator keeps the installation safe while the second
        // is deleted.
        User::factory()->create(['role' => 'admin', 'is_active' => true]);

        Livewire::test(Users::class)
            ->call('delete', $second->id)
            ->assertHasNoErrors();

        $this->assertNull(User::query()->find($second->id));
    }

    public function test_an_admin_can_reset_a_password_and_the_user_must_change_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'operator', 'must_change_password' => false]);

        $this->actingAs($admin);

        Livewire::test(Users::class)
            ->call('startReset', $target->id)
            ->set('newPassword', 'reset-password-123')
            ->set('newPasswordConfirmation', 'reset-password-123')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertTrue(Hash::check('reset-password-123', $target->password));
        $this->assertTrue($target->must_change_password);
        $this->assertTrue(AuditEvent::query()->where('action', 'user.password_reset')->exists());
    }

    public function test_a_user_can_be_edited_without_touching_the_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create([
            'name' => 'Old Name',
            'email' => 'old@example.com',
            'role' => 'operator',
        ]);

        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['userId' => $target->id])
            ->set('name', 'Updated Name')
            ->set('email', 'updated@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertSame('Updated Name', $target->name);
        $this->assertSame('updated@example.com', $target->email);
        $this->assertNull($target->email_verified_at);
        $this->assertTrue(Hash::check('password', $target->password));
    }

    public function test_new_passwords_must_be_at_least_twelve_characters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(UserForm::class)
            ->set('name', 'Short Password')
            ->set('email', 'short@example.com')
            ->set('role', 'viewer')
            ->set('password', 'elevenchars')
            ->set('passwordConfirmation', 'elevenchars')
            ->call('save')
            ->assertHasErrors('password');

        $this->assertNull(User::query()->where('email', 'short@example.com')->first());
    }

    public function test_an_admin_reset_purges_the_target_sessions_and_rotates_the_remember_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'operator']);
        $remember = $target->remember_token;

        $this->actingAs($admin);

        foreach (['target-session-a', 'target-session-b'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $target->id,
                'ip_address' => '203.0.113.9',
                'user_agent' => 'PHPUnit',
                'payload' => 'payload',
                'last_activity' => now()->timestamp,
            ]);
        }

        Livewire::test(Users::class)
            ->call('startReset', $target->id)
            ->set('newPassword', 'twelve-chars-ok')
            ->set('newPasswordConfirmation', 'twelve-chars-ok')
            ->call('resetPassword')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-b']);
        $this->assertNotSame($remember, $target->fresh()->remember_token);
    }

    public function test_deactivating_a_user_rotates_their_remember_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'operator', 'is_active' => true]);
        $remember = $target->remember_token;

        $this->actingAs($admin);

        Livewire::test(UserForm::class, ['userId' => $target->id])
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($target->fresh()->is_active);
        $this->assertNotSame($remember, $target->fresh()->remember_token);
    }

    public function test_admin_password_resets_require_at_least_twelve_characters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'operator']);

        $this->actingAs($admin);

        Livewire::test(Users::class)
            ->call('startReset', $target->id)
            ->set('newPassword', 'elevenchars')
            ->set('newPasswordConfirmation', 'elevenchars')
            ->call('resetPassword')
            ->assertHasErrors('newPassword');

        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_the_last_admin_helper_is_not_public_api(): void
    {
        $method = new ReflectionMethod(Users::class, 'isLastActiveAdmin');

        $this->assertTrue($method->isPrivate());
    }
}
