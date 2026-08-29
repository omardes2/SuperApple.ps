<?php

namespace Tests\Feature\Sprint0;

use App\Enums\RoleName;
use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/employee')->assertRedirect('/login');
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk()->assertSee('تسجيل الدخول');
    }

    public function test_admin_user_logs_in_and_lands_on_admin_dashboard(): void
    {
        $admin = $this->makeUser(RoleName::SuperAdmin, ['email' => 'boss@test.local']);

        Livewire::test(Login::class)
            ->set('email', 'boss@test.local')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_plain_employee_logs_in_and_lands_on_employee_dashboard(): void
    {
        $this->makeUser(RoleName::Employee, ['email' => 'emp@test.local']);

        Livewire::test(Login::class)
            ->set('email', 'emp@test.local')
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(route('employee.dashboard'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->makeUser(RoleName::Employee, ['email' => 'emp@test.local']);

        Livewire::test(Login::class)
            ->set('email', 'emp@test.local')
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->makeUser(RoleName::Accountant, ['email' => 'off@test.local', 'is_active' => false]);

        Livewire::test(Login::class)
            ->set('email', 'off@test.local')
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_recorded_in_audit_log(): void
    {
        $this->makeUser(RoleName::Accountant, ['email' => 'acc@test.local']);

        Livewire::test(Login::class)
            ->set('email', 'acc@test.local')
            ->set('password', 'password')
            ->call('login');

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'module' => 'Auth']);
        $this->assertSame(1, AuditLog::where('action', 'login')->count());
    }

    public function test_user_can_log_out(): void
    {
        $user = $this->makeUser(RoleName::Employee);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'logout']);
    }
}
