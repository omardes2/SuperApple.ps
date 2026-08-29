<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_creates_a_super_admin_with_hidden_password_prompts(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'مدير', '--email' => 'boss@company.test'])
            ->expectsQuestion('كلمة المرور', 'SuperSecret10')
            ->expectsQuestion('تأكيد كلمة المرور', 'SuperSecret10')
            ->assertExitCode(0);

        $user = User::where('email', 'boss@company.test')->firstOrFail();
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('SuperSecret10', $user->password));
    }

    public function test_rejects_mismatched_passwords(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'x', '--email' => 'x@company.test'])
            ->expectsQuestion('كلمة المرور', 'SuperSecret10')
            ->expectsQuestion('تأكيد كلمة المرور', 'Different99')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'x@company.test']);
    }

    public function test_rejects_short_password(): void
    {
        $this->artisan('app:create-admin', ['--name' => 'x', '--email' => 'y@company.test'])
            ->expectsQuestion('كلمة المرور', 'short')
            ->expectsQuestion('تأكيد كلمة المرور', 'short')
            ->assertExitCode(1);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::create(['name' => 'existing', 'email' => 'dupe@company.test', 'password' => Hash::make('whatever10'), 'is_active' => true, 'locale' => 'ar']);

        $this->artisan('app:create-admin', ['--name' => 'x', '--email' => 'dupe@company.test'])
            ->expectsQuestion('كلمة المرور', 'SuperSecret10')
            ->expectsQuestion('تأكيد كلمة المرور', 'SuperSecret10')
            ->assertExitCode(1);
    }
}
