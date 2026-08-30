<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Livewire\Admin\InvoicesIndex;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\InvoiceService;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Production runtime-stability regression suite.
 *
 * Reproduces (and locks the fix for) the 500 that struck the quotation/invoice
 * line editor: selecting a service whose nullable financial columns
 * (default_price_usd / tax_rate) are null, or clearing a live numeric field,
 * fed "" into the live DocumentCalculator preview and Money::of("") threw a
 * NumberFormatException. The GET-only smoke tests never fired the interactive
 * `updatedLines` event with a null-financial service, so they stayed green.
 *
 * Also sweeps every major admin/employee page for a plain render, and checks
 * that empty and production-seeded database states do not 500.
 */
class RuntimeStabilityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** A service whose financial columns are all null — the exact production shape. */
    private function serviceWithNullFinancials(): Service
    {
        return Service::create([
            'service_code' => 'SRV-NULL',
            'name' => 'خدمة بلا تسعير',
            'service_type' => 'one_time',
            'default_price_usd' => null,
            'estimated_cost_ils' => null,
            'tax_rate' => null,
            'is_active' => true,
        ]);
    }

    private function draftInvoice(): Invoice
    {
        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.50'],
            [['item_name' => 'بند', 'quantity' => 1, 'unit_price_usd' => 100, 'tax_rate' => 0]],
        );
    }

    // ---------------------------------------------------------------------
    // 1. Selecting a service must never throw (the confirmed 500).
    // ---------------------------------------------------------------------

    public function test_selecting_service_with_null_financials_in_quotation_does_not_throw(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();
        $service = $this->serviceWithNullFinancials();

        $component = Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.service_id', $service->id)
            ->assertHasNoErrors();

        // Name snapshotted; nullable price/tax normalised to "0" (never "").
        $component->assertSet('lines.0.item_name', $service->name)
            ->assertSet('lines.0.unit_price_usd', '0')
            ->assertSet('lines.0.tax_rate', '0');

        // The live preview computed without exploding.
        $preview = $component->viewData('preview');
        $this->assertSame('0.00', $preview['lines'][0]['line_total_usd']);
        $this->assertSame('0.00', $preview['total_usd']);
    }

    public function test_selecting_service_with_null_financials_in_invoice_does_not_throw(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = $this->draftInvoice();
        $service = $this->serviceWithNullFinancials();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.service_id', $service->id)
            ->assertHasNoErrors()
            ->assertSet('lines.0.item_name', $service->name)
            ->assertSet('lines.0.unit_price_usd', '0');
    }

    public function test_selecting_priced_service_snapshots_price_and_tax(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();
        $service = Service::create([
            'service_code' => 'SRV-PRICED', 'name' => 'تصميم هوية', 'service_type' => 'one_time',
            'default_price_usd' => '500.00', 'tax_rate' => '16.00', 'is_active' => true,
        ]);

        $component = Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.service_id', $service->id)
            ->set('lines.0.quantity', 2)
            ->assertHasNoErrors()
            ->assertSet('lines.0.item_name', 'تصميم هوية')
            ->assertSet('lines.0.unit_price_usd', '500.00')
            ->assertSet('lines.0.tax_rate', '16.00');

        // 2 × 500 = 1000 gross, +16% tax = 1160.
        $preview = $component->viewData('preview');
        $this->assertSame('1000.00', $preview['lines'][0]['line_subtotal_usd']);
        $this->assertSame('160.00', $preview['lines'][0]['tax_usd']);
        $this->assertSame('1160.00', $preview['lines'][0]['line_total_usd']);
    }

    // ---------------------------------------------------------------------
    // 2. Clearing a live numeric field must never throw.
    // ---------------------------------------------------------------------

    public function test_clearing_numeric_line_fields_does_not_throw(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.unit_price_usd', '')   // cleared price
            ->set('lines.0.quantity', '')          // cleared quantity
            ->set('lines.0.tax_rate', '')          // cleared tax
            ->set('lines.0.discount_type', 'fixed')
            ->set('lines.0.discount_value', '')    // cleared discount
            ->assertHasNoErrors();
    }

    public function test_zero_priced_custom_service_line_works(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();

        $component = Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.service_id', '')       // manual line
            ->set('lines.0.item_name', 'بند مجاني')
            ->set('lines.0.unit_price_usd', 0)
            ->assertHasNoErrors();

        $this->assertSame('0.00', $component->viewData('preview')['total_usd']);
    }

    public function test_changing_service_after_first_selection_recomputes(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();
        $nullSvc = $this->serviceWithNullFinancials();
        $priced = Service::create([
            'service_code' => 'SRV-2', 'name' => 'استضافة', 'service_type' => 'monthly',
            'default_price_usd' => '120.00', 'tax_rate' => '0', 'is_active' => true,
        ]);

        $component = Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.service_id', $nullSvc->id)   // first: null-priced
            ->assertHasNoErrors()
            ->set('lines.0.service_id', $priced->id)    // then switch
            ->assertHasNoErrors()
            ->assertSet('lines.0.unit_price_usd', '120.00');

        $this->assertSame('120.00', $component->viewData('preview')['total_usd']);
    }

    public function test_add_and_remove_multiple_lines_stays_stable(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();
        $svc = $this->serviceWithNullFinancials();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->call('addLine')
            ->set('lines.1.service_id', $svc->id)       // null-priced second line
            ->assertHasNoErrors()
            ->call('addLine')
            ->set('lines.2.unit_price_usd', 50)
            ->assertHasNoErrors()
            ->call('removeLine', 1)                     // remove the null one
            ->assertHasNoErrors()
            ->assertCount('lines', 2);
    }

    // ---------------------------------------------------------------------
    // 3. Recalculation correctness (quotation + invoice) after a save.
    // ---------------------------------------------------------------------

    public function test_quotation_recalculation_is_correct_after_service_select(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $q = $this->draftInvoice();
        $svc = Service::create([
            'service_code' => 'SRV-R', 'name' => 'خدمة', 'service_type' => 'one_time',
            'default_price_usd' => '200.00', 'tax_rate' => '10', 'is_active' => true,
        ]);

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $q])
            ->set('lines.0.service_id', $svc->id)
            ->set('lines.0.quantity', 3)
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $q->fresh();
        // 3 × 200 = 600, +10% = 660.
        $this->assertSame('600.00', $fresh->subtotal_usd);
        $this->assertSame('60.00', $fresh->tax_usd);
        $this->assertSame('660.00', $fresh->total_usd);
    }

    public function test_invoice_recalculation_and_ils_conversion_are_correct(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = $this->draftInvoice();

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.unit_price_usd', 1000)
            ->set('lines.0.tax_rate', 0)
            ->set('exchange_rate', '3.50')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1000.00', $invoice->fresh()->total_usd);
        $this->assertSame('3.500000', $invoice->fresh()->exchange_rate);
    }

    // ---------------------------------------------------------------------
    // 4. Issued invoice immutability is preserved.
    // ---------------------------------------------------------------------

    public function test_issued_invoice_remains_immutable(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => '3.28'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 2000, 'tax_rate' => 0]],
        );
        $issued = app(InvoiceService::class)->issue($invoice);
        $this->assertSame(InvoiceStatus::Issued, $issued->status);
        $lockedRate = $issued->exchange_rate;
        $lockedIls = $issued->total_ils_at_issue;

        // The service layer refuses to edit a non-draft invoice.
        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->updateDraft($issued->fresh(), [
            'exchange_rate' => '9.99',
        ], [['item_name' => 'تلاعب', 'quantity' => 5, 'unit_price_usd' => 5000]]);

        // (Guard against silent mutation even if the exception path changed.)
        $this->assertSame($lockedRate, $issued->fresh()->exchange_rate);
        $this->assertSame($lockedIls, $issued->fresh()->total_ils_at_issue);
    }

    // ---------------------------------------------------------------------
    // 5. Page render sweep — admin (authorized) + employee.
    // ---------------------------------------------------------------------

    /** @return list<string> */
    private function adminUrls(): array
    {
        return [
            '/admin', '/admin/customers', '/admin/services',
            '/admin/invoices', '/admin/payments', '/admin/whatsapp',
            '/admin/whatsapp/templates', '/admin/whatsapp/reminders',
            '/admin/tasks', '/admin/departments', '/admin/employees',
            '/admin/attendance', '/admin/leaves', '/admin/payroll', '/admin/payroll/reports',
            '/admin/advances', '/admin/expenses', '/admin/suppliers', '/admin/cash-banks',
            '/admin/accounting/chart', '/admin/accounting/journals', '/admin/accounting/trial-balance',
            '/admin/accounting/general-ledger', '/admin/accounting/profit-loss',
            '/admin/accounting/balance-sheet', '/admin/accounting/reconciliation',
            '/admin/reports', '/admin/reports/ar-aging', '/admin/reports/customers',
            '/admin/reports/whatsapp',
            '/admin/reports/exchange-gain-loss', '/admin/settings', '/admin/users', '/admin/roles',
            '/admin/notifications', '/admin/activity', '/admin/audit-log', '/admin/production-readiness',
        ];
    }

    public function test_all_admin_pages_render_for_super_admin(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_all_admin_pages_render_with_empty_database(): void
    {
        // Only roles seeded — no customers/services/invoices/settings at all.
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        foreach ($this->adminUrls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_admin_pages_render_under_production_seed_state(): void
    {
        $this->seed(ProductionSeeder::class); // idempotent over seedRoles()
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));

        foreach (['/admin', '/admin/leaves', '/admin/settings', '/admin/accounting/chart'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_all_employee_pages_render(): void
    {
        [$user] = $this->makeStaff();
        $this->actingAs($user);

        foreach ([
            '/employee', '/employee/attendance', '/employee/leaves',
            '/employee/payslips', '/employee/tasks',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    // ---------------------------------------------------------------------
    // 6. Financial authorization stays intact.
    // ---------------------------------------------------------------------

    public function test_employee_cannot_access_admin_finance_routes(): void
    {
        [$user] = $this->makeStaff();

        foreach (['/admin/invoices', '/admin/payments', '/admin/accounting/chart'] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    public function test_employee_cannot_render_finance_components(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(InvoicesIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(PaymentsIndex::class)->assertForbidden();
    }
}
