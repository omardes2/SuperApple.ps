<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Enums\TaskMemberStatus;
use App\Enums\TaskStatus;
use App\Livewire\Employee\MyTasks;
use App\Livewire\Employee\TaskShow as EmployeeTaskShow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskMemberService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The collaborative employee task workflow: create form (customer + services +
 * ad budget), per-member start/complete, participant management, team-based
 * completion, and the security guards. No financial data is ever exposed.
 */
class EmployeeTaskWorkflowTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private int $svcSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return array{0:User,1:Employee} */
    private function employee(): array
    {
        return $this->makeStaff(RoleName::Employee);
    }

    private function service(bool $ad = false, bool $active = true, string $price = '100.00'): Service
    {
        $this->svcSeq++;

        return Service::create([
            'service_code' => 'TSV-'.str_pad((string) $this->svcSeq, 4, '0', STR_PAD_LEFT),
            'name' => ($ad ? 'إعلانات ممولة ' : 'خدمة ').$this->svcSeq,
            'category' => $ad ? 'إعلانات' : 'تصميم',
            'service_type' => 'custom',
            'requires_ad_budget' => $ad,
            'default_price_usd' => $price,
            'estimated_cost_ils' => '150.00',
            'tax_rate' => 16,
            'is_active' => $active,
        ]);
    }

    /** Create a task through the service as the given user (creator = primary). */
    private function taskFor(User $user, Employee $employee, array $overrides = []): Task
    {
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $service = $this->service();

        return app(TaskService::class)->create(array_merge([
            'title' => 'مهمة تعاونية',
            'customer_id' => $customer->id,
            'service_ids' => [$service->id],
            'primary_assignee_id' => $employee->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'priority' => 'normal',
        ], $overrides));
    }

    // ============================================================ Create form

    public function test_create_modal_renders_with_customer_and_services(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)
            ->call('create')
            ->assertSet('showForm', true)
            ->assertSee('العميل')
            ->assertSee('الخدمات');
    }

    public function test_customer_search_by_name(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $this->makeCustomer(['name' => 'توفير اون لاين']);

        Livewire::test(MyTasks::class)->call('create')
            ->set('customerSearch', 'توفير')
            ->assertSee('توفير اون لاين');
    }

    public function test_customer_search_by_code(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $c = $this->makeCustomer(['name' => 'عميل بالرقم']);

        Livewire::test(MyTasks::class)->call('create')
            ->set('customerSearch', $c->customer_number)
            ->assertSee('عميل بالرقم');
    }

    public function test_customer_search_by_whatsapp(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $this->makeCustomer(['name' => 'عميل واتساب', 'whatsapp_number' => '0599432037']);

        Livewire::test(MyTasks::class)->call('create')
            ->set('customerSearch', '0599432037')
            ->assertSee('عميل واتساب');
    }

    public function test_service_prices_are_not_exposed_in_picker(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $this->service(price: '987.65');

        Livewire::test(MyTasks::class)->call('create')
            ->set('serviceSearch', 'خدمة')
            ->assertDontSee('987.65');
    }

    public function test_employee_can_select_one_and_multiple_services(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $s1 = $this->service();
        $s2 = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $s1->id)
            ->assertCount('selectedServiceIds', 1)
            ->call('toggleService', $s2->id)
            ->assertCount('selectedServiceIds', 2)
            ->call('toggleService', $s1->id)
            ->assertCount('selectedServiceIds', 1);
    }

    public function test_start_and_end_dates_default_to_today(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)->call('create')
            ->assertSet('start_date', now()->toDateString())
            ->assertSet('due_date', now()->toDateString());
    }

    public function test_employee_can_change_dates(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)->call('create')
            ->set('start_date', '2026-09-01')
            ->set('due_date', '2026-09-05')
            ->assertSet('due_date', '2026-09-05');
    }

    public function test_full_create_stores_customer_and_services(): void
    {
        [$user, $employee] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $s1 = $this->service();
        $s2 = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'حملة رمضان')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $s1->id)
            ->call('toggleService', $s2->id)
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::latest('id')->first();
        $this->assertSame($customer->id, $task->customer_id);
        $this->assertSame($employee->id, $task->primary_assignee_id);
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $task->services->pluck('id')->all());
    }

    public function test_customer_is_required(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $s = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'بدون عميل')
            ->call('toggleService', $s->id)
            ->call('save')
            ->assertHasErrors('customer_id');
    }

    public function test_at_least_one_service_required(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'بدون خدمات')
            ->call('selectCustomer', $customer->id)
            ->call('save')
            ->assertHasErrors('selectedServiceIds');
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $s = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'تواريخ خاطئة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $s->id)
            ->set('start_date', '2026-09-10')
            ->set('due_date', '2026-09-01')
            ->call('save')
            ->assertHasErrors('due_date');
    }

    // ============================================================ Ads service

    public function test_selecting_ads_service_shows_budget_field(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->assertSee('قيمة الإعلانات الممولة');
    }

    public function test_removing_ads_service_clears_budget(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '500')
            ->call('toggleService', $ad->id)
            ->assertSet('ad_budget_amount', null)
            ->assertDontSee('قيمة الإعلانات الممولة للحملة');
    }

    public function test_budget_required_only_when_ads_service_selected(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'إعلان بدون ميزانية')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->call('save')
            ->assertHasErrors('ad_budget_amount');
    }

    public function test_budget_stored_on_task_without_accounting(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);
        $journalsBefore = JournalEntry::count();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'حملة ممولة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '500')
            ->set('ad_budget_currency', 'ILS')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::latest('id')->first();
        $this->assertSame('500.00', $task->ad_budget_amount);
        $this->assertSame('ILS', $task->ad_budget_currency);
        // No accounting entry is ever created for a campaign budget.
        $this->assertSame($journalsBefore, JournalEntry::count());
    }

    public function test_ad_budget_currency_defaults_to_ils(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)->call('create')->assertSet('ad_budget_currency', 'ILS');
    }

    // ============================================================ Participants

    public function test_primary_can_add_active_colleague(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);

        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        $this->assertTrue($task->fresh()->isActiveMember($empB));
        $this->assertCount(2, $task->fresh()->activeMembers);
    }

    public function test_duplicate_colleague_cannot_be_added(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->addParticipant($task->fresh(), $empB, $userA);
    }

    public function test_inactive_colleague_cannot_be_added(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $empB->update(['is_active' => false]);
        $task = $this->taskFor($userA, $empA);

        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->addParticipant($task, $empB->fresh(), $userA);
    }

    public function test_participant_sees_and_can_open_task(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        $this->assertTrue($task->fresh()->isVisibleTo($userB));

        $this->actingAs($userB);
        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])->assertOk();
    }

    public function test_non_member_cannot_open_task(): void
    {
        [$userA, $empA] = $this->employee();
        [$userC] = $this->employee();
        $task = $this->taskFor($userA, $empA);

        $this->actingAs($userC);
        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])->assertForbidden();
    }

    public function test_ordinary_participant_cannot_manage_team(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        [, $empC] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        // Participant B is not creator/primary and lacks tasks.assign.
        $this->assertFalse(app(TaskMemberService::class)->canManageTeam($task->fresh(), $userB));
        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->addParticipant($task->fresh(), $empC, $userB);
    }

    public function test_primary_can_remove_not_started_participant(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        app(TaskMemberService::class)->removeParticipant($task->fresh(), $empB, $userA);

        $this->assertFalse($task->fresh()->isActiveMember($empB));
    }

    public function test_added_employee_receives_notification(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);

        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $userB->id]);
    }

    public function test_cannot_add_participant_to_completed_task(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        // Single member completes → task completed.
        app(TaskMemberService::class)->start($task->fresh(), $userA);
        app(TaskMemberService::class)->complete($task->fresh(), $userA);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->addParticipant($task->fresh(), $empB, $userA);
    }

    // ============================================================ Start / Complete

    public function test_start_moves_task_to_in_progress_and_sets_started_at(): void
    {
        [$userA, $empA] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        $this->assertSame(TaskStatus::New, $task->status);

        app(TaskMemberService::class)->start($task->fresh(), $userA);

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $member = $fresh->memberFor($empA);
        $this->assertSame(TaskMemberStatus::InProgress->value, $member->pivot->status);
        $this->assertNotNull($member->pivot->started_at);
    }

    public function test_non_member_cannot_start(): void
    {
        [$userA, $empA] = $this->employee();
        [$userC] = $this->employee();
        $task = $this->taskFor($userA, $empA);

        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->start($task->fresh(), $userC);
    }

    public function test_member_can_complete_own_and_sets_completed_at(): void
    {
        [$userA, $empA] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->start($task->fresh(), $userA);
        app(TaskMemberService::class)->complete($task->fresh(), $userA);

        $member = $task->fresh()->memberFor($empA);
        $this->assertSame(TaskMemberStatus::Completed->value, $member->pivot->status);
        $this->assertNotNull($member->pivot->completed_at);
    }

    public function test_member_cannot_complete_for_another(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);
        app(TaskMemberService::class)->start($task->fresh(), $userB);

        // B completing only affects B — A stays untouched.
        app(TaskMemberService::class)->complete($task->fresh(), $userB);

        $fresh = $task->fresh();
        $this->assertSame(TaskMemberStatus::Completed->value, $fresh->memberFor($empB)->pivot->status);
        $this->assertSame(TaskMemberStatus::NotStarted->value, $fresh->memberFor($empA)->pivot->status);
    }

    public function test_one_completed_member_does_not_complete_multi_member_task(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        app(TaskMemberService::class)->start($task->fresh(), $userA);
        app(TaskMemberService::class)->complete($task->fresh(), $userA);

        $this->assertNotSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_two_of_three_completed_does_not_complete_task(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        [$userC, $empC] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);
        app(TaskMemberService::class)->addParticipant($task->fresh(), $empC, $userA);

        foreach ([$userA, $userB] as $u) {
            app(TaskMemberService::class)->start($task->fresh(), $u);
            app(TaskMemberService::class)->complete($task->fresh(), $u);
        }

        $this->assertNotSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_all_members_completed_completes_task(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        foreach ([$userA, $userB] as $u) {
            app(TaskMemberService::class)->start($task->fresh(), $u);
            app(TaskMemberService::class)->complete($task->fresh(), $u);
        }

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_single_member_completion_completes_task(): void
    {
        [$userA, $empA] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->start($task->fresh(), $userA);
        app(TaskMemberService::class)->complete($task->fresh(), $userA);

        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_adding_member_to_in_progress_task_prevents_premature_completion(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        [$userC, $empC] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        app(TaskMemberService::class)->start($task->fresh(), $userA);
        app(TaskMemberService::class)->complete($task->fresh(), $userA);
        // Add C while task is in progress.
        app(TaskMemberService::class)->addParticipant($task->fresh(), $empC, $userA);

        // B finishes but C has not → still open.
        app(TaskMemberService::class)->start($task->fresh(), $userB);
        app(TaskMemberService::class)->complete($task->fresh(), $userB);
        $this->assertNotSame(TaskStatus::Completed, $task->fresh()->status);

        // C finishes → now complete.
        app(TaskMemberService::class)->start($task->fresh(), $userC);
        app(TaskMemberService::class)->complete($task->fresh(), $userC);
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    // ============================================================ UI workflow

    public function test_employee_ui_shows_start_then_complete_and_no_review(): void
    {
        [$userA, $empA] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        $this->actingAs($userA);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('بدء المهمة')
            ->assertDontSee('طلب مراجعة')
            ->assertDontSee('إرسال للمراجعة')
            ->call('startMine')
            ->assertSee('إتمام المهمة')
            ->call('completeMine')
            ->assertSee('تم إتمام عملك');
    }

    public function test_team_card_renders_member_status(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $empB->update(['full_name' => 'سميحة عابدين']);
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB->fresh(), $userA);
        $this->actingAs($userA);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('فريق المهمة')
            ->assertSee('سميحة عابدين')
            ->assertSee('مسؤول')
            ->assertSee('مشارك');
    }

    public function test_creator_can_add_participant_via_ui_without_assign_permission(): void
    {
        [$userA, $empA] = $this->employee();
        [, $empB] = $this->employee();
        $empB->update(['full_name' => 'نور عابدين']);
        $task = $this->taskFor($userA, $empA);
        $this->actingAs($userA);

        $this->assertFalse($userA->can('tasks.assign'));

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->set('participantSearch', 'نور')
            ->call('addParticipant', $empB->id)
            ->assertHasNoErrors();

        $this->assertTrue($task->fresh()->isActiveMember($empB));
    }

    // ============================================================ Security

    public function test_employee_role_has_no_assign_permission(): void
    {
        [$user] = $this->employee();
        $this->assertFalse($user->can('tasks.assign'));
        $this->assertFalse($user->can('tasks.manage'));
    }

    public function test_completing_own_does_not_change_peer_status_via_ui(): void
    {
        [$userA, $empA] = $this->employee();
        [$userB, $empB] = $this->employee();
        $task = $this->taskFor($userA, $empA);
        app(TaskMemberService::class)->addParticipant($task, $empB, $userA);

        // B acts through the UI — only B's own state moves.
        $this->actingAs($userB);
        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->call('startMine')->call('completeMine');

        $fresh = $task->fresh();
        $this->assertSame(TaskMemberStatus::Completed->value, $fresh->memberFor($empB)->pivot->status);
        $this->assertSame(TaskMemberStatus::NotStarted->value, $fresh->memberFor($empA)->pivot->status);
    }

    public function test_primary_assignee_cannot_be_removed(): void
    {
        [$userA, $empA] = $this->employee();
        $task = $this->taskFor($userA, $empA);

        $this->expectException(\RuntimeException::class);
        app(TaskMemberService::class)->removeParticipant($task->fresh(), $empA, $userA);
    }

    public function test_task_show_does_not_expose_service_price(): void
    {
        [$userA, $empA] = $this->employee();
        $this->actingAs($userA);
        $customer = $this->makeCustomer();
        $service = $this->service(price: '654.32');
        $task = app(TaskService::class)->create([
            'title' => 'سعر مخفي',
            'customer_id' => $customer->id,
            'service_ids' => [$service->id],
            'primary_assignee_id' => $empA->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'priority' => 'normal',
        ]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee($service->name)
            ->assertDontSee('654.32');
    }
}
