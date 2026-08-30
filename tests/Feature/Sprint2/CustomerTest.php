<?php

namespace Tests\Feature\Sprint2;

use App\Enums\CustomerStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\CustomersIndex;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_admin_can_create_customer_with_auto_number(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        $customer = app(CustomerService::class)->create(['name' => 'شركة الأفق', 'phone' => '0591111111']);

        $this->assertNotNull($customer->customer_number);
        $this->assertStringStartsWith('CUS-', $customer->customer_number);
        $this->assertDatabaseHas('customers', ['name' => 'شركة الأفق']);
    }

    public function test_customer_has_no_email_field(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'email'));
        $this->assertFalse(Schema::hasColumn('customers', 'contact_email'));
        $this->assertNotContains('email', (new Customer)->getFillable());
    }

    public function test_customer_whatsapp_is_required(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);

        Livewire::actingAs($manager)->test(CustomersIndex::class)
            ->call('create')
            ->set('name', 'بلا واتساب')
            ->set('whatsapp_number', '')
            ->call('save')
            ->assertHasErrors(['whatsapp_number' => 'required']);
    }

    public function test_employee_cannot_access_customer_management(): void
    {
        [$user] = $this->makeStaff();

        $this->actingAs($user)->get('/admin/customers')->assertRedirect(route('employee.dashboard'));
        Livewire::actingAs($user)->test(CustomersIndex::class)->assertForbidden();
    }

    public function test_employee_sees_only_customers_linked_to_their_work(): void
    {
        [$user, $employee] = $this->makeStaff();

        $mine = $this->makeCustomer(['name' => 'عميلي']);
        $other = $this->makeCustomer(['name' => 'عميل آخر']);

        // Link the employee to `mine` via a project membership.
        $project = $this->makeProject($mine);
        $project->memberships()->create(['employee_id' => $employee->id, 'joined_at' => now()]);

        $visible = Customer::visibleTo($user)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($other->id));
    }

    public function test_manager_sees_all_customers(): void
    {
        $manager = $this->makeUser(RoleName::GeneralManager);
        $this->makeCustomer();
        $this->makeCustomer();

        $this->assertSame(2, Customer::visibleTo($manager)->count());
    }

    public function test_customer_archive_keeps_the_record(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();
        $this->makeProject($customer); // has a project

        app(CustomerService::class)->archive($customer);

        $customer->refresh();
        $this->assertSame(CustomerStatus::Archived, $customer->status);
        $this->assertFalse($customer->is_active);
        // Not hard-deleted; its project still exists.
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertSame(1, $customer->projects()->count());
    }
}
