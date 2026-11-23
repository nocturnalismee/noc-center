<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    public function test_it_creates_a_verified_active_admin_with_a_hashed_password(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_trims_and_lowercases_the_email_and_unique_checks_case_insensitively(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => '  Admin@Example.COM  ',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->assertSame('admin@example.com', User::query()->sole()->email);

        $this->artisan('noc:create-admin', [
            '--name' => 'Duplicate Admin',
            '--email' => 'ADMIN@EXAMPLE.COM',
            '--password' => self::PASSWORD,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_it_never_prints_the_password(): void
    {
        $exitCode = Artisan::call('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString(self::PASSWORD, Artisan::output());
    }

    public function test_it_refuses_to_run_when_a_user_already_exists(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->artisan('noc:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'second@example.com',
            '--password' => self::PASSWORD,
        ])
            ->expectsOutputToContain('already exists')
            ->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_force_creates_another_admin(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->artisan('noc:create-admin', [
            '--name' => 'Second Admin',
            '--email' => 'second@example.com',
            '--password' => self::PASSWORD,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, User::count());
        $this->assertSame(UserRole::Admin, User::where('email', 'second@example.com')->firstOrFail()->role());
    }

    public function test_force_still_requires_a_unique_email(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
        ])->assertSuccessful();

        $this->artisan('noc:create-admin', [
            '--name' => 'Duplicate Admin',
            '--email' => 'admin@example.com',
            '--password' => self::PASSWORD,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(1, User::count());
    }

    public function test_it_rejects_a_password_shorter_than_twelve_characters(): void
    {
        $this->artisan('noc:create-admin', [
            '--name' => 'Admin User',
            '--email' => 'admin@example.com',
            '--password' => 'short-pass',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
