<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Enums\ServiceType;
use App\Exceptions\IssuedInvoiceImmutableException;
use App\Livewire\Admin\CustomerProfile;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\InvoiceShow;
use App\Livewire\Admin\SettingsPage;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Service;
use App\Models\Subscription;
use App\Services\CustomerBalanceService;
use App\Services\GlobalSearchService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\AdminNavigation;
use App\Support\CurrencyDisplay;
use App\Support\Money;
use App\Support\Permissions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Retirement of the Subscriptions and standalone Exchange-Rate modules.
 *
 * Functional retirement only: the exchange_rates / subscriptions tables, the
 * invoice.exchange_rate / payment.exchange_rate / invoice.subscription_id
 * columns, and the legacy models/services stay. What is removed is the UI, the
 * routes, the permissions and — crucially — any dependence of the live invoice
 * and payment workflow on a central/latest/default exchange rate. Every rate is
 * now entered by hand, per document, and the payment rate is independent of the
 * invoice rate. Accounting rules and FX gain/loss are untouched.
 */
class SubscriptionExchangeRetirementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    /** @return list<string> every route referenced in the admin sidebar */
    private function navRoutes(): array
    {
        return collect(AdminNavigation::groups())
            ->flatMap(fn ($g) => Arr::pluck($g['items'], 'route'))
            ->filter()->values()->all();
    }

    // ---------------------------------------------------------- Subscriptions

    public function test_sidebar_has_no_subscriptions_item(): void
    {
        $this->assertNotContains('admin.subscriptions', $this->navRoutes());
    }

    public function test_subscription_routes_do_not_exist(): void
    {
        foreach ([
            'admin.subscriptions', 'admin.subscriptions.show', 'admin.subscriptions.create',
            'admin.subscriptions.edit', 'admin.subscriptions.pause', 'admin.subscriptions.resume',
            'admin.subscriptions.cancel', 'admin.reports.subscriptions',
        ] as $name) {
            $this->assertFalse(app('router')->has($name), "route [{$name}] must not exist");
        }
    }

    public function test_removed_subscription_pages_return_not_found(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get('/admin/subscriptions')->assertNotFound();
        $this->get('/admin/reports/subscriptions')->assertNotFound();
    }

    public function test_subscription_permissions_are_gone_from_the_catalog(): void
    {
        $all = Permissions::all();
        foreach ([
            'subscriptions.view', 'subscriptions.create', 'subscriptions.edit', 'subscriptions.bill',
            'subscriptions.pause', 'subscriptions.resume', 'subscriptions.cancel', 'subscriptions.manage',
            'reports.subscriptions',
        ] as $perm) {
            $this->assertNotContains($perm, $all, "permission [{$perm}] must be retired");
        }
    }

    public function test_customer_profile_has_no_subscriptions_tab(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(CustomerProfile::class, ['customer' => $this->makeCustomer()])
            ->assertOk()
            ->assertDontSee('الاشتراكات');
    }

    public function test_dashboard_has_no_mrr_or_arr_cards(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(Dashboard::class)->assertOk()->assertDontSee('MRR')->assertDontSee('ARR');
    }

    public function test_global_search_has_no_subscriptions_group(): void
    {
        $this->actingAs($sa = $this->makeUser(RoleName::SuperAdmin));
        $keys = array_column(app(GlobalSearchService::class)->search($sa, 'ا'), 'key');
        $this->assertNotContains('subscriptions', $keys);
    }

    public function test_billing_scheduler_is_removed_but_reminders_remain(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->map(fn ($e) => $e->command ?? '')->implode(' ');
        $this->assertStringNotContainsString('subscriptions:bill', $events);
        $this->assertStringContainsString('payments:send-reminders', $events);
    }

    public function test_choosing_a_monthly_service_never_auto_creates_a_subscription(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        Service::create([
            'service_code' => 'SRV-M', 'name' => 'خدمة شهرية', 'service_type' => ServiceType::Monthly->value,
            'default_price_usd' => 300, 'is_active' => true,
        ]);

        // Invoicing a monthly service is a plain one-off invoice — no recurrence.
        $this->makeIssuedInvoice($this->makeCustomer(), '300', '3.20');

        $this->assertSame(0, Subscription::count());
    }

    public function test_legacy_subscription_row_is_preserved(): void
    {
        // The historical table still exists; a legacy row persists untouched.
        $customer = $this->makeCustomer();
        $sub = $this->makeActiveSubscription($customer);

        $this->assertDatabaseHas('subscriptions', ['id' => $sub->id]);
        $this->assertNotNull(Subscription::find($sub->id));
    }

    // --------------------------------------------------------- Exchange rates

    public function test_sidebar_has_no_exchange_rates_item(): void
    {
        $this->assertNotContains('admin.exchange-rates', $this->navRoutes());
    }

    public function test_exchange_rate_routes_do_not_exist(): void
    {
        foreach (['admin.exchange-rates', 'admin.exchange-rates.create', 'admin.exchange-rates.edit'] as $name) {
            $this->assertFalse(app('router')->has($name), "route [{$name}] must not exist");
        }
    }

    public function test_removed_exchange_rate_page_returns_not_found(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->get('/admin/exchange-rates')->assertNotFound();
    }

    public function test_exchange_rate_permissions_are_gone_from_the_catalog(): void
    {
        $all = Permissions::all();
        $this->assertNotContains('exchange_rates.view', $all);
        $this->assertNotContains('exchange_rates.manage', $all);
    }

    public function test_settings_page_has_no_central_default_rate_field(): void
    {
        $this->actingAs($this->makeUser(RoleName::SuperAdmin));
        Livewire::test(SettingsPage::class)
            ->assertOk()
            ->assertDontSee('سعر الصرف الافتراضي'); // central default-rate field removed
    }

    // ----------------------------------------------------- Invoice manual rate

    public function test_draft_invoice_never_auto_fetches_a_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        // A legacy exchange-rate row exists, but nothing consults it.
        $this->seedExchangeRate(now()->toDateString(), '4.00');

        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => null],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);

        $this->assertNull($invoice->exchange_rate); // starts blank, no auto-fetch
    }

    public function test_invoice_rate_is_entered_manually_and_locked_on_issue(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => null],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 1000]]);

        // Manual entry via the component.
        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('exchange_rate', '3.08')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3.080000', $invoice->fresh()->exchange_rate);

        // Issue locks it; a later direct write is rejected.
        app(InvoiceService::class)->issue($invoice->fresh());
        $this->expectException(IssuedInvoiceImmutableException::class);
        $invoice->fresh()->update(['exchange_rate' => '9.99']);
    }

    public function test_invoice_cannot_be_issued_without_a_valid_rate(): void
    {
        $gm = $this->makeUser(RoleName::GeneralManager);
        $this->actingAs($gm);
        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer()->id, 'exchange_rate' => null],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 500]]);

        Livewire::actingAs($gm)->test(InvoiceShow::class, ['invoice' => $invoice])
            ->call('issue')
            ->assertHasErrors('action');

        $this->assertSame(InvoiceStatus::Draft, $invoice->fresh()->status);
    }

    public function test_issued_invoice_ils_uses_its_own_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        // $2,000 × 3.08 = 6,160 ILS snapshot.
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '2000', '3.08');

        $this->assertSame('3.080000', $invoice->exchange_rate);
        $this->assertSame('6160.00', $invoice->total_ils_at_issue);
    }

    // ----------------------------------------------------- Payment manual rate

    public function test_ils_payment_without_a_rate_cannot_post(): void
    {
        $this->actingAs($this->makeUser(RoleName::Accountant));
        $customer = $this->makeCustomer();

        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('ILS')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'ILS', 'payment_amount' => 3300, 'exchange_rate' => null,
            'payment_date' => '2026-08-10',
        ]);

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->post($payment, []);
    }

    public function test_payment_rate_is_manual_independent_of_invoice_and_drives_usd_and_fx(): void
    {
        $this->actingAs($this->makeUser(RoleName::Accountant));
        $customer = $this->makeCustomer();

        // Invoice locked at 3.08 …
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.08');

        // … but the payment carries its OWN, different manual rate (3.30).
        $payment = app(PaymentService::class)->createDraft([
            'account_id' => $this->cashAccount('ILS')->id, 'customer_id' => $customer->id,
            'payment_currency' => 'ILS', 'payment_amount' => 3300, 'exchange_rate' => '3.30',
            'payment_date' => '2026-08-10',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);

        $payment->refresh();
        // USD equivalent uses the PAYMENT's own rate: 3300 ÷ 3.30 = 1000.
        $this->assertSame('1000.00', $payment->usd_equivalent);
        $this->assertSame('3.300000', $payment->exchange_rate);

        // The invoice's own locked rate is untouched by the payment's rate.
        $this->assertSame('3.080000', $invoice->fresh()->exchange_rate);
        $this->assertSame('1000.00', $invoice->fresh()->paid_usd_equivalent);
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);

        // FX difference is recorded on the allocation (invoice rate ≠ payment rate).
        $alloc = PaymentAllocation::where('payment_id', $payment->id)->first();
        $this->assertNotNull($alloc);
        $this->assertNotNull($alloc->exchange_difference_ils);
        // 1000 × (3.30 − 3.08) = 220.00 ILS gain.
        $this->assertSame('220.00', Money::money($alloc->exchange_difference_ils));
    }

    // --------------------------------------------------------- Currency display

    public function test_currency_display_never_resolves_a_central_rate(): void
    {
        $this->seedExchangeRate(now()->toDateString(), '3.50');
        $display = app(CurrencyDisplay::class);

        $this->assertNull($display->latestOrDefaultRate());
        $this->assertNull($display->estimatedIls('100'));
    }

    public function test_aggregate_ils_sums_each_document_at_its_own_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '100', '3.00'); // 300 ILS
        $this->makeIssuedInvoice($customer, '100', '3.20'); // 320 ILS

        // 300 + 320 = 620 — never 200 × one blind rate.
        $this->assertSame('620.00', app(CustomerBalanceService::class)->outstandingIlsByDocument($customer));
        // …and the retired blind estimate is gone.
        $this->assertNull(app(CustomerBalanceService::class)->estimatedOutstandingIls($customer));
    }

    public function test_service_price_with_no_document_rate_shows_usd_only(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        // Even with a legacy rate row, a bare USD amount renders USD only, no ≈₪.
        $this->seedExchangeRate(now()->toDateString(), '3.50');

        $html = Blade::render(
            '<x-money :usd="$usd" :useLatest="true" />', ['usd' => '500.00']);
        $this->assertStringContainsString('$500.00', $html);
        $this->assertStringNotContainsString('₪', $html);
    }

    public function test_invoice_list_renders_without_a_central_rate_and_uses_locked_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '50.00', '3.08'); // 154.00 ILS locked
        $this->seedExchangeRate(now()->toDateString(), '4.00'); // must be ignored

        $this->get(route('admin.invoices'))
            ->assertOk()
            ->assertSee('$50.00')
            ->assertSee('154.00 ₪')
            ->assertDontSee('200.00 ₪'); // NOT 50 × 4.00
    }

    // -------------------------------------------------------------- Regression

    public function test_integrity_command_stays_green_after_retirement(): void
    {
        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    public function test_employee_finance_permissions_are_unchanged(): void
    {
        [$user] = $this->makeStaff();
        foreach (['invoices.view', 'payments.view', 'accounting.view'] as $perm) {
            $this->assertFalse($user->can($perm), "employee must NOT have [{$perm}]");
        }

        $accountant = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($accountant->can('invoices.issue'));
        $this->assertTrue($accountant->can('payments.post'));
    }
}
