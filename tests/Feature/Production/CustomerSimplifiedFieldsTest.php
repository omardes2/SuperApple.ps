<?php

namespace Tests\Feature\Production;

use App\Enums\CustomerStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\CustomersIndex;
use App\Models\Customer;
use App\Services\GlobalSearchService;
use App\Services\Settings;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customer create/edit workflow is reduced to three operational fields —
 * name, WhatsApp number, notes. Every other column (contact_person, phone,
 * city, address, tax_number, category, source, status) stays in the database
 * as legacy but is no longer shown, required, or depended on when creating a
 * customer. WhatsApp is the primary contact channel.
 */
class CustomerSimplifiedFieldsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function form(): Testable
    {
        return Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomersIndex::class)
            ->call('create');
    }

    // ---- The create modal shows only the three fields ----

    public function test_create_modal_shows_name(): void
    {
        $this->form()->assertSee('الاسم');
    }

    public function test_create_modal_shows_whatsapp(): void
    {
        $this->form()->assertSee('رقم واتساب');
    }

    public function test_create_modal_shows_notes(): void
    {
        $this->form()->assertSee('ملاحظات');
    }

    public function test_create_modal_hides_contact_person(): void
    {
        $this->form()->assertDontSee('الشخص المسؤول');
    }

    public function test_create_modal_hides_separate_phone(): void
    {
        $this->form()->assertDontSee('الهاتف');
    }

    public function test_create_modal_hides_city(): void
    {
        $this->form()->assertDontSee('المدينة');
    }

    public function test_create_modal_hides_category(): void
    {
        $this->form()->assertDontSee('التصنيف');
    }

    public function test_create_modal_hides_source(): void
    {
        $this->form()->assertDontSee('المصدر');
    }

    public function test_create_modal_hides_tax_number(): void
    {
        $this->form()->assertDontSee('الرقم الضريبي');
    }

    public function test_create_modal_hides_address(): void
    {
        $this->form()->assertDontSee('العنوان');
    }

    // ---- Creating a customer ----

    public function test_can_create_customer_with_only_name_and_whatsapp(): void
    {
        $this->form()
            ->set('name', 'توفير اون لاين')
            ->set('whatsapp_number', '0599432037')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'name' => 'توفير اون لاين',
            'whatsapp_number' => '0599432037',
        ]);
    }

    public function test_notes_are_optional(): void
    {
        $this->form()
            ->set('name', 'شركة ABC')
            ->set('whatsapp_number', '972599432037')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['name' => 'شركة ABC', 'notes' => null]);
    }

    public function test_whatsapp_accepts_local_and_international_palestinian_formats(): void
    {
        foreach (['0599432037', '972599432037', '+972 59 943 2037'] as $number) {
            $this->form()
                ->set('name', 'عميل '.$number)
                ->set('whatsapp_number', $number)
                ->call('save')
                ->assertHasNoErrors();
        }
    }

    public function test_customer_number_is_generated_automatically(): void
    {
        $this->form()
            ->set('name', 'محمد أحمد')
            ->set('whatsapp_number', '0599432037')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'محمد أحمد')->first();
        $this->assertNotNull($customer);
        $this->assertStringStartsWith('CUS-', $customer->customer_number);
    }

    public function test_new_customer_is_active_by_default(): void
    {
        $this->form()
            ->set('name', 'عميل نشط')
            ->set('whatsapp_number', '0599432037')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'عميل نشط')->first();
        $this->assertTrue($customer->is_active);
        $this->assertSame(CustomerStatus::Active, $customer->status);
    }

    // ---- Editing a customer ----

    public function test_edit_shows_only_the_three_fields_and_preserves_legacy_data(): void
    {
        $customer = $this->makeCustomer([
            'name' => 'عميل قديم', 'whatsapp_number' => '0599432037',
            'phone' => '0561111111', 'city' => 'رام الله',
            'contact_person' => 'أبو أحمد', 'tax_number' => '12345',
            'notes' => 'ملاحظة قديمة',
        ]);

        $component = Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomersIndex::class)
            ->call('edit', $customer->id)
            ->assertSet('name', 'عميل قديم')
            ->assertSet('whatsapp_number', '0599432037')
            ->assertSet('notes', 'ملاحظة قديمة')
            ->assertDontSee('الشخص المسؤول')
            ->assertDontSee('المدينة')
            ->assertDontSee('الرقم الضريبي');

        // Editing only the three fields never wipes the legacy columns.
        $component->set('name', 'عميل محدّث')->call('save')->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame('عميل محدّث', $customer->name);
        $this->assertSame('0561111111', $customer->phone);      // legacy, untouched
        $this->assertSame('رام الله', $customer->city);          // legacy, untouched
        $this->assertSame('أبو أحمد', $customer->contact_person); // legacy, untouched
    }

    // ---- Search ----

    public function test_search_by_name(): void
    {
        $this->makeCustomer(['name' => 'مؤسسة النجمة', 'whatsapp_number' => '0599000001']);
        $this->makeCustomer(['name' => 'مؤسسة القمر', 'whatsapp_number' => '0599000002']);

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomersIndex::class)
            ->set('search', 'النجمة')
            ->assertSee('مؤسسة النجمة')
            ->assertDontSee('مؤسسة القمر');
    }

    public function test_search_by_customer_number(): void
    {
        $c = $this->makeCustomer(['name' => 'عميل بالرقم', 'customer_number' => 'CUS-90001']);
        $this->makeCustomer(['name' => 'عميل آخر', 'customer_number' => 'CUS-90002']);

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomersIndex::class)
            ->set('search', 'CUS-90001')
            ->assertSee('عميل بالرقم')
            ->assertDontSee('عميل آخر');
    }

    public function test_search_by_whatsapp_number(): void
    {
        $this->makeCustomer(['name' => 'صاحب واتساب', 'whatsapp_number' => '0599432037']);
        $this->makeCustomer(['name' => 'عميل مختلف', 'whatsapp_number' => '0561234567']);

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(CustomersIndex::class)
            ->set('search', '0599432037')
            ->assertSee('صاحب واتساب')
            ->assertDontSee('عميل مختلف');
    }

    public function test_global_search_finds_customer_by_whatsapp(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $this->makeCustomer(['name' => 'عميل البحث', 'whatsapp_number' => '0599432037']);

        $keys = array_column(app(GlobalSearchService::class)->search($gm, '0599432037'), 'key');
        $this->assertContains('customers', $keys);
    }

    // ---- WhatsApp / relations / permissions ----

    public function test_whatsapp_reminder_uses_the_whatsapp_number(): void
    {
        app(Settings::class)->set('whatsapp', 'default_country_code', '972', 'string');
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037', 'phone' => '0561111111']);

        $resolved = app(WhatsAppService::class)->resolvePhone($customer);

        // The WhatsApp number wins over the legacy phone.
        $this->assertNotNull($resolved);
        $this->assertStringContainsString('599432037', $resolved);
        $this->assertStringNotContainsString('561111111', $resolved);
    }

    public function test_invoice_customer_relation_is_unaffected(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '500', '3.20');

        $this->assertSame($customer->id, $invoice->fresh()->customer_id);
        $this->assertTrue($customer->invoices()->whereKey($invoice->id)->exists());
    }

    public function test_task_customer_relation_is_unaffected(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $task = $this->makeTask(['customer_id' => $customer->id]);

        $this->assertSame($customer->id, $task->fresh()->customer_id);
        $this->assertTrue($customer->tasks()->whereKey($task->id)->exists());
    }

    public function test_customer_profile_renders_without_legacy_fields(): void
    {
        $customer = $this->makeCustomer([
            'name' => 'عميل الملف', 'whatsapp_number' => '0599432037',
            'contact_person' => 'أبو أحمد', 'city' => 'نابلس', 'tax_number' => '99999',
        ]);

        Livewire::actingAs($this->makeUser(RoleName::SuperAdmin))
            ->test(CustomerProfile::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee('عميل الملف')
            ->assertSee('واتساب')
            ->assertDontSee('الشخص المسؤول')
            ->assertDontSee('العنوان')
            ->assertDontSee('الرقم الضريبي');
    }

    public function test_employee_financial_permissions_are_unchanged(): void
    {
        [$user] = $this->makeStaff();
        foreach (['invoices.view', 'payments.view', 'accounting.view'] as $perm) {
            $this->assertFalse($user->can($perm), "employee must NOT have [{$perm}]");
        }
        // Customer management stays gated exactly as before.
        Livewire::actingAs($user)->test(CustomersIndex::class)->assertForbidden();
    }
}
