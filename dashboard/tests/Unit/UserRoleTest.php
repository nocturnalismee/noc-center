<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_enum_has_the_expected_backed_values(): void
    {
        $this->assertSame('admin', UserRole::Admin->value);
        $this->assertSame('operator', UserRole::Operator->value);
        $this->assertSame('viewer', UserRole::Viewer->value);
    }

    public function test_new_users_default_to_the_viewer_role(): void
    {
        $user = User::factory()->create();
        $user->refresh();

        $this->assertSame(UserRole::Viewer, $user->role);
        $this->assertSame(UserRole::Viewer, $user->role());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mass Assigned',
            'email' => 'mass-assigned@example.com',
            'password' => 'a-secure-password',
            'role' => UserRole::Admin->value,
        ]);

        $this->assertSame(UserRole::Viewer, $user->fresh()->role());
    }

    public function test_is_admin_and_can_operate_follow_the_role(): void
    {
        $expectations = [
            [UserRole::Admin, true, true],
            [UserRole::Operator, false, true],
            [UserRole::Viewer, false, false],
        ];

        foreach ($expectations as [$role, $isAdmin, $canOperate]) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();

            $this->assertSame($role, $user->role());
            $this->assertSame($isAdmin, $user->isAdmin());
            $this->assertSame($canOperate, $user->canOperate());
        }
    }
}
