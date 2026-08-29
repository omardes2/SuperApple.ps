<?php

namespace Tests\Feature\Sprint5;

use App\Enums\RoleName;
use App\Models\JournalEntry;
use App\Services\ExpenseService;
use App\Services\SupplierBillService;
use App\Services\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::Accountant));
    }

    public function test_accounting_report_pages_render(): void
    {
        foreach ([
            '/admin/accounting/chart',
            '/admin/accounting/journals',
            '/admin/accounting/general-ledger',
            '/admin/accounting/trial-balance',
            '/admin/accounting/profit-loss',
            '/admin/accounting/balance-sheet',
            '/admin/accounting/reconciliation',
            '/admin/cash-banks',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_expense_pages_render(): void
    {
        $cash = $this->makeCashAccount('ILS');
        $expense = app(ExpenseService::class)->createDraft([
            'category_id' => $this->expenseCategory()->id, 'description' => 'x',
            'currency' => 'ILS', 'amount' => '100', 'financial_account_id' => $cash->id,
        ]);

        $this->get('/admin/expenses')->assertOk();
        $this->get(route('admin.expenses.show', $expense))->assertOk()->assertSee($expense->expense_number);
    }

    public function test_supplier_pages_render(): void
    {
        $supplier = $this->makeSupplier();
        $bill = app(SupplierBillService::class)->createDraft(
            ['supplier_id' => $supplier->id, 'bill_date' => '2026-08-05', 'currency' => 'ILS'],
            [['description' => 'a', 'quantity' => 1, 'unit_price' => '500']],
        );
        $payment = app(SupplierPaymentService::class)->createDraft([
            'supplier_id' => $supplier->id, 'currency' => 'ILS', 'amount' => '0',
        ]);

        $this->get('/admin/suppliers')->assertOk();
        $this->get(route('admin.suppliers.show', $supplier))->assertOk()->assertSee($supplier->name);
        $this->get(route('admin.supplier-bills.show', $bill))->assertOk()->assertSee($bill->bill_number);
        $this->get(route('admin.supplier-payments.show', $payment))->assertOk()->assertSee($payment->payment_number);
    }

    public function test_journal_detail_renders(): void
    {
        $this->makeIssuedInvoice($this->makeCustomer(), '1000', '3.30');
        $journal = JournalEntry::firstOrFail();

        $this->get(route('admin.journals.show', $journal))->assertOk()->assertSee($journal->journal_number);
    }

    public function test_dashboard_shows_accounting_cards_for_accountant(): void
    {
        $this->get('/admin')->assertOk()->assertSee('المحاسبة');
    }
}
