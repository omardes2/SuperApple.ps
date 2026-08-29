<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use App\Livewire\Admin\NotificationCenter;
use App\Livewire\Admin\UsersIndex;
use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class NotificationsUsersLoginTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function notify(User $user, string $type): void
    {
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Generic',
            'data' => ['type' => $type, 'title' => 'x', 'message' => 'y'],
        ]);
    }

    public function test_category_mapping(): void
    {
        $this->assertSame('finance', NotificationCenter::categoryOf('invoice.issued'));
        $this->assertSame('hr', NotificationCenter::categoryOf('leave.submitted'));
        $this->assertSame('subscriptions', NotificationCenter::categoryOf('subscription.auto_issue_failed'));
        $this->assertSame('whatsapp', NotificationCenter::categoryOf('whatsapp.send_failed'));
        $this->assertSame('tasks', NotificationCenter::categoryOf('task.assigned'));
    }

    public function test_finance_notification_is_hidden_from_user_without_finance_permission(): void
    {
        // A PM has notifications.view but no finance permissions.
        $pm = $this->makeUser(RoleName::ProjectManager);
        $this->notify($pm, 'invoice.issued');   // finance — must be hidden
        $this->notify($pm, 'task.assigned');    // tasks — visible

        Livewire::actingAs($pm)->test(NotificationCenter::class)
            ->assertViewHas('notifications', fn ($n) => $n->count() === 1);
    }

    public function test_user_can_be_created_and_deactivated(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        Livewire::actingAs($gm)->test(UsersIndex::class)
            ->call('openCreate')
            ->set('name', 'مستخدم جديد')
            ->set('email', 'newuser@test.local')
            ->set('password', 'secret123')
            ->set('role', RoleName::Accountant->value)
            ->call('save')
            ->assertHasNoErrors();

        $u = User::where('email', 'newuser@test.local')->firstOrFail();
        $this->assertTrue($u->hasRole(RoleName::Accountant->value));
        $this->assertTrue($u->is_active);

        Livewire::actingAs($gm)->test(UsersIndex::class)->call('toggleActive', $u->id);
        $this->assertFalse($u->fresh()->is_active);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        User::create([
            'name' => 'معطّل', 'email' => 'inactive@test.local',
            'password' => Hash::make('password123'), 'is_active' => false, 'locale' => 'ar',
        ]);

        Livewire::test(Login::class)
            ->set('email', 'inactive@test.local')
            ->set('password', 'password123')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        RateLimiter::clear(strtolower('victim@test.local').'|127.0.0.1');
        User::create([
            'name' => 'ضحية', 'email' => 'victim@test.local',
            'password' => Hash::make('correct-password'), 'is_active' => true, 'locale' => 'ar',
        ]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)->set('email', 'victim@test.local')->set('password', 'wrong')->call('login')->assertHasErrors('email');
        }

        // The 6th attempt is throttled — even the correct password is refused.
        Livewire::test(Login::class)->set('email', 'victim@test.local')->set('password', 'correct-password')->call('login')->assertHasErrors('email');
        $this->assertGuest();
    }
}
