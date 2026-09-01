<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Models\Customer;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The customer profile has a "كشف حساب" tab showing the read-only account
 * statement (from CustomerStatementService), gated on customer_statements.view.
 */
class CustomerStatementTabTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function profile(Customer $customer, ?User $user = null)
    {
        return Livewire::actingAs($user ?? $this->makeUser(RoleName::GeneralManager))
            ->test(CustomerProfile::class, ['customer' => $customer]);
    }

    public function test_statement_tab_is_visible(): void
    {
        $this->profile($this->makeCustomer())->assertSee('كشف حساب');
    }

    public function test_statement_tab_shows_invoice_and_payment_movements(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $svc = app(PaymentService::class);
        $p = $svc->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '128.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $svc->post($p, [['invoice_id' => $invoice->id, 'allocated_usd' => '40.00']]);

        $this->profile($customer)
            ->call('setTab', 'statement')
            ->assertViewHas('statement', fn ($s) => count($s['entries']) >= 2)
            ->assertSee($invoice->invoice_number)
            ->assertSee('الرصيد الختامي (USD)')
            ->assertSee('$100.00')  // invoice debit
            ->assertSee('$40.00');  // payment credit
    }

    public function test_statement_tab_hidden_without_permission(): void
    {
        $role = Role::findOrCreate('عارض عملاء فقط', 'web');
        $role->syncPermissions(['customers.view']);
        $user = $this->makeUser(RoleName::GeneralManager);
        $user->syncRoles(['عارض عملاء فقط']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->profile($this->makeCustomer(), $user->fresh())
            ->assertDontSee('كشف حساب');
    }

    public function test_statement_tab_empty_customer_renders(): void
    {
        $this->profile($this->makeCustomer())
            ->call('setTab', 'statement')
            ->assertOk()
            ->assertSee('لا حركات على الحساب.');
    }
}
