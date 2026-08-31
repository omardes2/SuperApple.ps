<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\TaskShow as AdminTaskShow;
use App\Livewire\Employee\TaskShow as EmployeeTaskShow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The employee task page sidebar: a single "الخدمات وميزانية الحملة" card that
 * lists the task's services (names only) and, when present, the funded-ads
 * campaign budget — replacing the attachments box and de-duplicating services
 * out of the details card. Admin attachments stay untouched.
 */
class EmployeeTaskServicesCardTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private int $seq = 0;

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

    private function service(bool $ad = false, string $price = '100.00', ?string $name = null): Service
    {
        $this->seq++;

        return Service::create([
            'service_code' => 'CRD-'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'name' => $name ?? (($ad ? 'اعلانات ممولة ' : 'خدمة ').$this->seq),
            'category' => $ad ? 'إعلانات' : 'تصميم',
            'service_type' => 'custom',
            'requires_ad_budget' => $ad,
            'default_price_usd' => $price,
            'is_active' => true,
        ]);
    }

    /** @param  list<int>  $serviceIds */
    private function task(User $user, Employee $employee, array $serviceIds, ?string $budget = null, string $currency = 'USD'): Task
    {
        $this->actingAs($user);

        return app(TaskService::class)->create([
            'title' => 'مهمة الصندوق',
            'customer_id' => $this->makeCustomer()->id,
            'service_ids' => $serviceIds,
            'primary_assignee_id' => $employee->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'priority' => 'normal',
            'ad_budget_amount' => $budget,
            'ad_budget_currency' => $budget !== null ? $currency : null,
        ]);
    }

    public function test_attachments_card_is_not_shown_to_employee(): void
    {
        [$user, $emp] = $this->employee();
        $task = $this->task($user, $emp, [$this->service()->id]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertDontSee('المرفقات');
    }

    public function test_services_card_is_shown(): void
    {
        [$user, $emp] = $this->employee();
        $task = $this->task($user, $emp, [$this->service()->id]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('الخدمات وميزانية الحملة');
    }

    public function test_all_task_services_are_shown_without_duplication(): void
    {
        [$user, $emp] = $this->employee();
        $s1 = $this->service(name: 'تصوير فيديو ريلز');
        $s2 = $this->service(name: 'تصميم منشورات');
        $s3 = $this->service(ad: true, name: 'اعلانات ممولة');
        $task = $this->task($user, $emp, [$s1->id, $s2->id, $s3->id], budget: '100');

        $html = Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('تصوير فيديو ريلز')
            ->assertSee('تصميم منشورات')
            ->assertSee('اعلانات ممولة')
            ->html();

        // Each service name appears exactly once (no duplicate details listing).
        $this->assertSame(1, substr_count($html, 'تصوير فيديو ريلز'));
        $this->assertSame(1, substr_count($html, 'تصميم منشورات'));
    }

    public function test_service_prices_are_not_shown(): void
    {
        [$user, $emp] = $this->employee();
        $s = $this->service(price: '888.88');
        $task = $this->task($user, $emp, [$s->id]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee($s->name)
            ->assertDontSee('888.88');
    }

    public function test_ad_budget_shows_inside_services_card_with_currency(): void
    {
        [$user, $emp] = $this->employee();
        $ad = $this->service(ad: true);
        $task = $this->task($user, $emp, [$ad->id], budget: '100', currency: 'USD');

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('ميزانية الإعلانات الممولة')
            ->assertSee('100.00 USD')
            ->assertSee('ميزانية الحملة الإعلانية');
    }

    public function test_ils_budget_renders(): void
    {
        [$user, $emp] = $this->employee();
        $ad = $this->service(ad: true);
        $task = $this->task($user, $emp, [$ad->id], budget: '500', currency: 'ILS');

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('500.00 ILS');
    }

    public function test_task_without_ads_shows_services_only(): void
    {
        [$user, $emp] = $this->employee();
        $task = $this->task($user, $emp, [$this->service()->id]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('الخدمات وميزانية الحملة')
            ->assertDontSee('ميزانية الإعلانات الممولة');
    }

    public function test_task_with_ad_service_but_no_budget_hides_budget_section(): void
    {
        [$user, $emp] = $this->employee();
        // Ad service attached but no budget value stored.
        $ad = $this->service(ad: true);
        $task = $this->task($user, $emp, [$ad->id], budget: null);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertDontSee('ميزانية الإعلانات الممولة');
    }

    public function test_services_are_not_duplicated_in_details_card(): void
    {
        [$user, $emp] = $this->employee();
        $s = $this->service(name: 'خدمة فريدة للتحقق');
        $task = $this->task($user, $emp, [$s->id]);

        $html = Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])->html();
        // The details card no longer lists services → the name appears once only.
        $this->assertSame(1, substr_count($html, 'خدمة فريدة للتحقق'));
        // Details card keeps customer/priority/dates.
        $this->assertStringContainsString('العميل', $html);
        $this->assertStringContainsString('الأولوية', $html);
    }

    public function test_admin_task_page_still_shows_attachments(): void
    {
        [$user, $emp] = $this->employee();
        $task = $this->task($user, $emp, [$this->service()->id]);

        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(AdminTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('المرفقات');
    }
}
