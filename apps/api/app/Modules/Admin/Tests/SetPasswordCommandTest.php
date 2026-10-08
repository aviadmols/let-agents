<?php

namespace App\Modules\Admin\Tests;

use App\Modules\Admin\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** A lost password is set again from the console, and only through an environment variable. */
final class SetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_users_password_is_set_from_an_environment_variable(): void
    {
        $user = User::factory()->operator()->create();

        putenv('TEST_NEW_PASSWORD=Aa-new-password-12');

        try {
            $this->artisan('admin:password', ['email' => strtoupper($user->email), '--password-env' => 'TEST_NEW_PASSWORD', '--no-interaction' => true])
                ->assertSuccessful();
        } finally {
            putenv('TEST_NEW_PASSWORD');
        }

        $this->assertTrue(Hash::check('Aa-new-password-12', $user->fresh()->password));
    }

    public function test_a_short_password_an_unknown_user_or_no_password_at_all_changes_nothing(): void
    {
        $user = User::factory()->operator()->create();
        $before = $user->password;

        putenv('TEST_NEW_PASSWORD=short');

        try {
            $this->artisan('admin:password', ['email' => $user->email, '--password-env' => 'TEST_NEW_PASSWORD', '--no-interaction' => true])->assertFailed();
            $this->artisan('admin:password', ['email' => 'nobody@example.com', '--password-env' => 'TEST_NEW_PASSWORD', '--no-interaction' => true])->assertFailed();
        } finally {
            putenv('TEST_NEW_PASSWORD');
        }

        $this->artisan('admin:password', ['email' => $user->email, '--no-interaction' => true])->assertFailed();
        $this->assertSame($before, $user->fresh()->password);
    }
}
