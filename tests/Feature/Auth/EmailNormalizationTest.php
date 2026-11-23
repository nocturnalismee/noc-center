<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Fortify's custom actions normalize the email before validation and save.
 *
 * Registration is disabled in this installation, but the actions remain part
 * of the Fortify contract and are exercised directly here so a future enable
 * cannot reintroduce mixed-case duplicates.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_new_user_trims_and_lowercases_the_email(): void
    {
        $user = $this->app->make(CreateNewUser::class)->create([
            'name' => 'New User',
            'email' => '  New.User@Example.COM  ',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $this->assertSame('new.user@example.com', $user->email);
    }

    public function test_create_new_user_rejects_a_case_insensitive_duplicate(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->expectException(ValidationException::class);

        $this->app->make(CreateNewUser::class)->create([
            'name' => 'Duplicate',
            'email' => 'TAKEN@EXAMPLE.COM',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);
    }

    public function test_update_user_profile_information_trims_and_lowercases_the_email(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->app->make(UpdateUserProfileInformation::class)->update($user, [
            'name' => 'Updated Name',
            'email' => '  Updated.Address@Example.COM  ',
        ]);

        $this->assertSame('updated.address@example.com', $user->fresh()->email);
    }

    public function test_update_user_profile_information_rejects_a_case_insensitive_duplicate(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->expectException(ValidationException::class);

        $this->app->make(UpdateUserProfileInformation::class)->update($user, [
            'name' => 'Owner',
            'email' => 'TAKEN@EXAMPLE.COM',
        ]);
    }
}
