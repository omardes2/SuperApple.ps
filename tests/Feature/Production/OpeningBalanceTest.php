<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\CustomersIndex;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\CustomerBalanceService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\CustomerStatementService;
use App\Services\PaymentReminderService;
use App\Services\PaymentService;
use App\Services\ReportsService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * End-to-end behaviour of accounting-safe customer opening balances: UI gating,
 * balance/statement/reports integration, payment allocation + FX, and the
 * guarantee that they never touch revenue and keep the books balanced.
 */
class OpeningBalanceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function accountant(): User
    {
        return $this->makeUser(RoleName::Accountant);
    }

    private function ob(): CustomerOpeningBalanceService
    {
        return app(CustomerOpeningBalanceService::class);
    }

    private function customerWithDebit(string $usd = '1000', string $rate = '3.10', string $date = '2026-01-01'): Customer
    {
        $this->actingAs($this->accountant());
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->ob()->create($customer, ['type' => 'debit', 'amount_usd' => $usd, 'exchange_rate' => $rate, 'balance_date' => $date]);

        return $customer->fresh();
    }

    // ---- Creation / UI gating ----

    public function test_customer_can_be_created_without_opening_balance(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(CustomersIndex::class)
            ->call('create')
            ->set('name', 'بلا رصيد')
            ->set('whatsapp_number', '0599432037')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'بلا رصيد')->first();
        $this->assertSame(0, $customer->openingBalances()->count());
    }

    public function test_finance_user_can_create_customer_with_opening_balance(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(CustomersIndex::class)
            ->call('create')
            ->set('name', 'مع رصيد')
            ->set('whatsapp_number', '0599432037')
            ->set('showOpeningBalance', true)
            ->set('obType', 'debit')
            ->set('obAmountUsd', '1000')
            ->set('obRate', '3.10')
            ->set('obDate', '2026-01-01')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'مع رصيد')->first();
        $ob = $customer->openingBalances()->first();
        $this->assertNotNull($ob);
        $this->assertSame('3100.00', $ob->amount_ils);
        $this->assertNotNull($ob->journal_entry_id);
    }

    public function test_customer_profile_renders_with_a_posted_opening_balance(): void
    {
        // Regression: the profile linked "عرض القيد" to a non-existent route
        // (admin.accounting.journals), so any customer carrying a posted opening
        // balance — every imported customer — 500'd on open. It must render and
        // point at the real journal route.
        $customer = $this->customerWithDebit();
        $ob = $customer->postedOpeningBalance();

        Livewire::actingAs($this->accountant())->test(CustomerProfile::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee('الرصيد الافتتاحي')
            ->assertSee('عرض القيد')
            ->assertSee(route('admin.journals.show', $ob->journal_entry_id), false);
    }

    public function test_customer_profile_without_journals_permission_still_renders(): void
    {
        // A user who can manage opening balances but cannot view journals sees the
        // balance without the "عرض القيد" link, and the page never errors.
        $customer = $this->customerWithDebit();

        // A custom role with opening-balance access but NO journals.view.
        $role = Role::findOrCreate('أمين أرصدة', 'web');
        $role->syncPermissions(['customers.view', 'customers.opening_balance.manage']);
        $user = $this->makeUser(RoleName::GeneralManager); // placeholder to reuse factory
        $user->syncRoles(['أمين أرصدة']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse($user->fresh()->can('journals.view'));

        Livewire::actingAs($user->fresh())->test(CustomerProfile::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee('الرصيد الافتتاحي')
            ->assertDontSee('عرض القيد');
    }

    public function test_non_finance_user_does_not_see_opening_balance_section(): void
    {
        // Project Manager can view customers but has no opening-balance permission.
        Livewire::actingAs($this->makeUser(RoleName::ProjectManager))->test(CustomersIndex::class)
            ->assertOk()
            ->assertDontSee('إضافة رصيد افتتاحي');
    }

    public function test_employee_cannot_access_customers_at_all(): void
    {
        [$employee] = $this->makeStaff();
        Livewire::actingAs($employee)->test(CustomersIndex::class)->assertForbidden();
        Livewire::actingAs($employee)->test(CustomerProfile::class, ['customer' => $this->makeCustomer()])->assertForbidden();
    }

    public function test_amount_rate_and_date_are_required(): void
    {
        $this->actingAs($this->accountant());
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);

        foreach ([
            ['amount_usd' => '0', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01'],
            ['amount_usd' => '1000', 'exchange_rate' => '0', 'balance_date' => '2026-01-01'],
            ['amount_usd' => '1000', 'exchange_rate' => '3.10', 'balance_date' => ''],
        ] as $bad) {
            try {
                $this->ob()->create($customer, array_merge(['type' => 'debit'], $bad));
                $this->fail('expected a validation failure for '.json_encode($bad));
            } catch (\RuntimeException $e) {
                $this->assertTrue(true);
            }
        }
    }

    // ---- Accounting isolation ----

    public function test_opening_balance_does_not_affect_profit_and_loss(): void
    {
        $this->customerWithDebit();
        $pl = app(AccountingReportService::class)->profitAndLoss('2026-01-01', '2026-12-31');
        $this->assertSame('0.00', $pl['total_revenue']);
        $this->assertSame('0.00', $pl['total_expense']);
    }

    public function test_balance_sheet_stays_balanced(): void
    {
        $this->customerWithDebit();
        $bs = app(AccountingReportService::class)->balanceSheet('2026-12-31');
        $this->assertTrue($bs['balanced']);
    }

    public function test_verify_integrity_stays_green(): void
    {
        $this->customerWithDebit();
        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    // ---- Balance / statement / reports ----

    public function test_outstanding_includes_opening_balance(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $this->assertSame('1000.00', app(CustomerBalanceService::class)->outstandingUsd($customer));
        $this->assertSame('3100.00', app(CustomerBalanceService::class)->outstandingIlsByDocument($customer));
    }

    public function test_customer_list_balance_includes_opening_balance(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $map = app(CustomerBalanceService::class)->outstandingMapForList([$customer->id]);
        $this->assertSame('1000.00', $map[$customer->id]['usd']);
        $this->assertSame('3100.00', $map[$customer->id]['ils']);
    }

    public function test_dashboard_total_receivables_includes_opening_balance(): void
    {
        $this->customerWithDebit('1000', '3.10');
        $this->assertSame('1000.00', app(ReportsService::class)->outstandingReceivablesUsd());
        $this->assertSame('3100.00', app(ReportsService::class)->receivablesIlsByDocument());
    }

    public function test_ar_aging_includes_opening_balance_by_its_date(): void
    {
        $this->customerWithDebit('1000', '3.10', '2026-01-01');
        $aging = app(ReportsService::class)->arAging('2026-03-31'); // ~89 days
        $this->assertSame('1000.00', Money($aging['total']));
        // Aged (not "current"): 90-day bucket boundary — it must not sit in current.
        $this->assertSame('0.00', Money($aging['buckets']['current']));
    }

    public function test_customer_statement_shows_opening_balance(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $statement = app(CustomerStatementService::class)->build($customer);
        $first = $statement['entries'][0];
        $this->assertSame('opening_balance', $first['type']);
        $this->assertSame('1000.00', $first['debit_usd']);
        $this->assertSame('1000.00', $first['balance_usd']);
    }

    public function test_credit_opening_balance_reduces_net_balance(): void
    {
        $this->actingAs($this->accountant());
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);
        $this->ob()->create($customer, ['type' => 'credit', 'amount_usd' => '500', 'exchange_rate' => '3.10', 'balance_date' => '2026-01-01']);

        $this->assertSame('500.00', app(CustomerBalanceService::class)->unallocatedCreditUsd($customer));
        $this->assertSame('-500.00', app(CustomerBalanceService::class)->netBalanceUsd($customer));
    }

    // ---- Payment allocation + FX ----

    public function test_partial_payment_reduces_opening_balance(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $ob = $customer->openingBalances()->first();

        // Pay $100 (via ILS at the same rate) allocated to the opening balance.
        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('ILS')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'ILS', 'payment_amount' => 310, 'exchange_rate' => '3.10', 'payment_date' => '2026-02-01',
        ]);
        app(PaymentService::class)->post($payment, [['opening_balance_id' => $ob->id, 'allocated_usd' => 100]]);

        $this->assertSame('900.00', $ob->fresh()->remaining_usd);
        $this->assertSame('900.00', app(CustomerBalanceService::class)->outstandingUsd($customer));
    }

    public function test_full_payment_clears_opening_balance_and_books_fx_gain(): void
    {
        $customer = $this->customerWithDebit('1000', '3.00'); // historical rate 3.00
        $ob = $customer->openingBalances()->first();

        // Pay the full $1000 as an ILS payment at 3.10 → gain of 1000×0.10 = 100 ₪.
        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('ILS')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'ILS', 'payment_amount' => 3100, 'exchange_rate' => '3.10', 'payment_date' => '2026-02-01',
        ]);
        app(PaymentService::class)->post($payment, [['opening_balance_id' => $ob->id, 'allocated_usd' => 1000]]);

        $this->assertSame('0.00', $ob->fresh()->remaining_usd);
        $alloc = $payment->allocations()->first();
        $this->assertSame($ob->id, $alloc->opening_balance_id);
        $this->assertSame('100.00', Money($alloc->exchange_difference_ils)); // FX gain

        // Cash/bank entry posted for the receipt.
        $entry = JournalEntry::with('lines')->where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail();
        $cashLine = $entry->lines->firstWhere('financial_account_id', $payment->account_id);
        $this->assertSame('3100.00', $cashLine->debit_ils);
        // Books remain green.
        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    public function test_usd_payment_against_opening_balance_uses_its_own_rate(): void
    {
        $customer = $this->customerWithDebit('1000', '3.00');
        $ob = $customer->openingBalances()->first();

        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('USD')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'USD', 'payment_amount' => 400, 'exchange_rate' => '3.20', 'payment_date' => '2026-02-01',
        ]);
        app(PaymentService::class)->post($payment, [['opening_balance_id' => $ob->id, 'allocated_usd' => 400]]);

        $this->assertSame('600.00', $ob->fresh()->remaining_usd);
        // FX: 400 × (3.20 − 3.00) = 80 ₪ gain.
        $this->assertSame('80.00', Money($payment->allocations()->first()->exchange_difference_ils));
    }

    public function test_auto_allocate_settles_opening_balance_first(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $this->makeIssuedInvoice($customer, '500', '3.10');

        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('USD')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'USD', 'payment_amount' => 200, 'exchange_rate' => '3.10', 'payment_date' => '2026-02-01',
        ]);
        $plan = app(PaymentService::class)->autoAllocatePlan($payment);
        $this->assertArrayHasKey('opening_balance_id', $plan[0]); // OB first
        $this->assertSame('200.00', $plan[0]['allocated_usd']);
    }

    // ---- WhatsApp / legacy ----

    public function test_whatsapp_reminder_outstanding_includes_opening_balance(): void
    {
        $customer = $this->customerWithDebit('1000', '3.10');
        $ctx = app(PaymentReminderService::class)->manualContext($customer);
        $this->assertSame('1000.00', $ctx['outstanding_usd']);
    }

    public function test_legacy_customer_without_opening_balance_renders(): void
    {
        $this->actingAs($this->accountant());
        $customer = $this->makeCustomer(['whatsapp_number' => '0599432037']);

        Livewire::actingAs($this->accountant())->test(CustomerProfile::class, ['customer' => $customer])
            ->assertOk()
            ->assertSee('الرصيد الافتتاحي')
            ->assertSee('إضافة رصيد افتتاحي');
    }
}

// Local helper mirroring Money::money() for terse assertions.
if (! function_exists('Tests\Feature\Production\Money')) {
    function Money($v): string
    {
        return Money::money($v);
    }
}
