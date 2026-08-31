<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Employee\MyTasks;
use App\Livewire\Employee\TaskShow as EmployeeTaskShow;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Service;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Database\Seeders\ServiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The funded-ads campaign-budget field on the employee "new task" form: it must
 * appear the instant a requires_ad_budget service is selected, stay hidden
 * otherwise, and clear on removal. Also covers the production data fix that
 * flags the real "اعلانات ممولة" service.
 */
class AdBudgetVisibilityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const LABEL = 'قيمة الإعلانات الممولة';

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

    private function service(bool $ad = false, string $price = '100.00', ?string $name = null, ?string $category = null): Service
    {
        $this->seq++;

        return Service::create([
            'service_code' => 'ADV-'.str_pad((string) $this->seq, 4, '0', STR_PAD_LEFT),
            'name' => $name ?? (($ad ? 'اعلانات ممولة ' : 'خدمة ').$this->seq),
            'category' => $category ?? ($ad ? 'إعلانات' : 'تصميم'),
            'service_type' => 'custom',
            'requires_ad_budget' => $ad,
            'default_price_usd' => $price,
            'is_active' => true,
        ]);
    }

    // ---- Visibility ----

    public function test_form_initially_hides_ad_budget(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)->call('create')->assertDontSee(self::LABEL);
    }

    public function test_selecting_normal_service_keeps_it_hidden(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $normal = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $normal->id)
            ->assertDontSee(self::LABEL);
    }

    public function test_selecting_funded_ads_service_shows_budget(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->assertSee(self::LABEL);
    }

    public function test_normal_plus_funded_ads_shows_budget_once(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $normal = $this->service();
        $ad = $this->service(ad: true);

        $html = Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $normal->id)
            ->call('toggleService', $ad->id)
            ->assertSee(self::LABEL)
            ->html();

        // The budget section renders exactly once, not per service.
        $this->assertSame(1, substr_count($html, 'قيمة الإعلانات الممولة للحملة'));
    }

    public function test_removing_funded_ads_hides_and_clears_budget(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '500')
            ->call('toggleService', $ad->id)
            ->assertSet('ad_budget_amount', null)
            ->assertSet('ad_budget_currency', 'ILS')
            ->assertDontSee(self::LABEL);
    }

    // ---- Validation ----

    public function test_budget_required_when_funded_ads_selected(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'حملة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->call('save')
            ->assertHasErrors('ad_budget_amount');
    }

    public function test_budget_not_required_without_funded_ads(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $normal = $this->service();

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'مهمة عادية')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $normal->id)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_budget_must_be_positive(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'حملة')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '0')
            ->call('save')
            ->assertHasErrors('ad_budget_amount');
    }

    // ---- Currency ----

    public function test_ils_is_the_default_currency(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);

        Livewire::test(MyTasks::class)->call('create')->assertSet('ad_budget_currency', 'ILS');
    }

    public function test_usd_can_be_selected_and_saved(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);

        Livewire::test(MyTasks::class)->call('create')
            ->set('title', 'حملة بالدولار')
            ->call('selectCustomer', $customer->id)
            ->call('toggleService', $ad->id)
            ->set('ad_budget_amount', '300')
            ->set('ad_budget_currency', 'USD')
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::latest('id')->first();
        $this->assertSame('300.00', $task->ad_budget_amount);
        $this->assertSame('USD', $task->ad_budget_currency);
    }

    // ---- Persistence & display ----

    public function test_task_saves_budget_without_accounting_entry(): void
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
            ->call('save')
            ->assertHasNoErrors();

        $task = Task::latest('id')->first();
        $this->assertSame('500.00', $task->ad_budget_amount);
        $this->assertSame('ILS', $task->ad_budget_currency);
        $this->assertSame($journalsBefore, JournalEntry::count());
    }

    public function test_task_details_show_budget(): void
    {
        [$user, $employee] = $this->employee();
        $this->actingAs($user);
        $customer = $this->makeCustomer();
        $ad = $this->service(ad: true);
        $task = app(TaskService::class)->create([
            'title' => 'عرض الميزانية',
            'customer_id' => $customer->id,
            'service_ids' => [$ad->id],
            'primary_assignee_id' => $employee->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'priority' => 'normal',
            'ad_budget_amount' => '500',
            'ad_budget_currency' => 'ILS',
        ]);

        Livewire::test(EmployeeTaskShow::class, ['task' => $task->fresh()])
            ->assertSee('ميزانية الإعلانات')
            ->assertSee('500.00');
    }

    public function test_employee_still_cannot_see_service_prices(): void
    {
        [$user] = $this->employee();
        $this->actingAs($user);
        $ad = $this->service(ad: true, price: '777.77');

        Livewire::test(MyTasks::class)->call('create')
            ->call('toggleService', $ad->id)
            ->assertDontSee('777.77');
    }

    // ---- Production data fix ----

    public function test_data_fix_flags_funded_ads_service_by_name(): void
    {
        // A pre-existing service the original backfill missed (no-hamza spelling,
        // non-advertising category, flag currently false).
        $s = Service::create([
            'service_code' => 'ADV-NAME', 'name' => 'اعلانات ممولة', 'category' => 'تسويق',
            'service_type' => 'custom', 'requires_ad_budget' => false, 'is_active' => true,
        ]);
        $this->assertFalse($s->requires_ad_budget);

        $affected = Service::flagFundedAds();

        $this->assertSame(1, $affected);
        $this->assertTrue($s->fresh()->requires_ad_budget);
        // Idempotent: a second run changes nothing.
        $this->assertSame(0, Service::flagFundedAds());
    }

    public function test_data_fix_flags_advertising_category_variant(): void
    {
        // Hamza-less category variant.
        $s = Service::create([
            'service_code' => 'ADV-CAT', 'name' => 'حملة', 'category' => 'اعلانات',
            'service_type' => 'custom', 'requires_ad_budget' => false, 'is_active' => true,
        ]);

        Service::flagFundedAds();
        $this->assertTrue($s->fresh()->requires_ad_budget);
    }

    public function test_data_fix_leaves_normal_services_untouched(): void
    {
        $s = $this->service(); // design service, flag false
        Service::flagFundedAds();
        $this->assertFalse($s->fresh()->requires_ad_budget);
    }

    public function test_seeded_funded_ads_service_carries_the_flag(): void
    {
        // The seeder authenticates as an existing user for auditing.
        $this->makeUser(RoleName::SuperAdmin);
        $this->seed(ServiceSeeder::class);

        $ad = Service::where('name', 'إعلانات ممولة')->first();
        $this->assertNotNull($ad);
        $this->assertTrue($ad->requires_ad_budget);
    }
}
