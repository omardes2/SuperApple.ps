<?php

namespace Tests\Feature\Sprint3;

use App\Enums\RoleName;
use App\Livewire\Admin\ExchangeRatesIndex;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FinancialSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function anInvoice(): Invoice
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $i = app(InvoiceService::class)->createDraft(['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.3'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
        auth()->logout();

        return $i;
    }

    public function test_employee_cannot_reach_financial_routes(): void
    {
        [$user] = $this->makeStaff();

        foreach (['/admin/invoices', '/admin/exchange-rates'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    public function test_employee_cannot_open_financial_detail_or_print(): void
    {
        $i = $this->anInvoice();
        [$user] = $this->makeStaff();

        $this->actingAs($user)->get(route('admin.invoices.show', $i))->assertRedirect(route('employee.dashboard'));
        $this->actingAs($user)->get(route('admin.invoices.print', $i))->assertRedirect(route('employee.dashboard'));
    }

    public function test_employee_cannot_enumerate_financial_documents_via_components(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(InvoicesIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(ExchangeRatesIndex::class)->assertForbidden();
    }

    public function test_project_manager_does_not_get_financial_documents(): void
    {
        $pm = $this->makeUser(RoleName::ProjectManager);

        foreach (['invoices.view', 'exchange_rates.view', 'invoices.issue'] as $perm) {
            $this->assertFalse($pm->can($perm), "PM must not have [{$perm}]");
        }
        $this->actingAs($pm)->get('/admin/invoices')->assertForbidden();
    }

    public function test_hr_manager_does_not_get_financial_documents(): void
    {
        $hr = $this->makeUser(RoleName::HrManager);

        $this->assertFalse($hr->can('invoices.view'));
        $this->assertFalse($hr->can('exchange_rates.view'));
    }

    public function test_accountant_can_access_financial_area(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);

        $this->assertTrue($accountant->can('invoices.issue'));
        $this->assertTrue($accountant->can('exchange_rates.manage'));

        $this->actingAs($accountant)->get('/admin/invoices')->assertOk();
        $this->actingAs($accountant)->get('/admin/exchange-rates')->assertOk();
    }

    public function test_service_financial_protection_still_holds(): void
    {
        // Regression guard from Sprint 2.
        [$user] = $this->makeStaff();
        $service = Service::create([
            'service_code' => 'SRV-Z', 'name' => 'خدمة', 'service_type' => 'one_time',
            'default_price_usd' => 500, 'is_active' => true,
        ]);

        Auth::login($user);
        $this->assertArrayNotHasKey('default_price_usd', $service->fresh()->toArray());
    }

    public function test_invoice_policy_blocks_edit_after_issue(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.3'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
        app(InvoiceService::class)->issue($invoice);

        // Policy update() must be false for an issued invoice.
        $this->assertFalse($accountant->can('update', $invoice->fresh()));
        $this->assertTrue($accountant->can('view', $invoice->fresh()));
    }
}
