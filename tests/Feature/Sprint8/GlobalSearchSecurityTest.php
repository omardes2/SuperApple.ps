<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use App\Services\GlobalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class GlobalSearchSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function search(): GlobalSearchService
    {
        return app(GlobalSearchService::class);
    }

    public function test_short_terms_return_nothing(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->assertSame([], $this->search()->search($gm, 'a'));
    }

    public function test_gm_can_find_an_invoice_by_number(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '500');

        $groups = $this->search()->search($gm, $invoice->invoice_number);
        $keys = array_column($groups, 'key');
        $this->assertContains('invoices', $keys);
    }

    public function test_employee_cannot_find_invoices_even_by_exact_number(): void
    {
        // Create an invoice as GM.
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        auth()->logout();

        // A plain employee must not see it in search results.
        [$employee] = $this->makeStaff();
        $groups = $this->search()->search($employee, $invoice->invoice_number);
        $this->assertSame([], $groups);
    }

    public function test_project_manager_cannot_find_invoices_or_payments(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '500');
        auth()->logout();

        // Subscriptions module was retired — it is no longer a global-search group.
        $pm = $this->makeUser(RoleName::ProjectManager);
        $payKeys = array_column($this->search()->search($pm, $invoice->invoice_number), 'key');
        $this->assertNotContains('invoices', $payKeys);
        $this->assertNotContains('payments', $payKeys);
        $this->assertNotContains('subscriptions', $payKeys);
    }
}
