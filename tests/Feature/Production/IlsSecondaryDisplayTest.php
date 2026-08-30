<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Services\CustomerBalanceService;
use App\Support\CurrencyDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The USD → "≈ X ₪" secondary display: a presentation estimate only. The
 * official value stays USD; the ILS line uses each amount's own context rate
 * (invoice/payment) or the latest/default estimate rate, never one blind rate
 * across mixed-rate aggregates.
 */
class IlsSecondaryDisplayTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function renderMoney(array $props): string
    {
        return Blade::render('<x-money :usd="$usd" :rate="$rate" :ils="$ils" :useLatest="$useLatest" />', [
            'usd' => $props['usd'] ?? 0,
            'rate' => $props['rate'] ?? null,
            'ils' => $props['ils'] ?? null,
            'useLatest' => $props['useLatest'] ?? false,
        ]);
    }

    public function test_invoice_amount_shows_ils_at_its_rate(): void
    {
        $html = $this->renderMoney(['usd' => '50.00', 'rate' => '3.08']);
        $this->assertStringContainsString('$50.00', $html);
        $this->assertStringContainsString('154.00 ₪', $html);
        $this->assertStringContainsString('≈', $html);
    }

    public function test_remaining_amount_ils_is_rounded_correctly(): void
    {
        // 5.67 × 3.08 = 17.4636 → 17.46
        $html = $this->renderMoney(['usd' => '5.67', 'rate' => '3.08']);
        $this->assertStringContainsString('$5.67', $html);
        $this->assertStringContainsString('17.46 ₪', $html);
    }

    public function test_precomputed_ils_is_used_verbatim(): void
    {
        // Aggregate/stored accounting value passed directly — not re-derived.
        $html = $this->renderMoney(['usd' => '200.00', 'ils' => '620.00', 'rate' => '3.00']);
        $this->assertStringContainsString('$200.00', $html);
        $this->assertStringContainsString('620.00 ₪', $html);
        $this->assertStringNotContainsString('600.00 ₪', $html); // NOT 200 × 3.00
    }

    public function test_no_rate_shows_usd_only_without_error(): void
    {
        $html = $this->renderMoney(['usd' => '99.00']);
        $this->assertStringContainsString('$99.00', $html);
        $this->assertStringNotContainsString('₪', $html);
    }

    public function test_zero_amount_renders_safely(): void
    {
        $html = $this->renderMoney(['usd' => '0', 'rate' => '3.08']);
        $this->assertStringContainsString('$0.00', $html);
        $this->assertStringContainsString('0.00 ₪', $html);
    }

    public function test_latest_rate_is_resolved_and_memoised(): void
    {
        $this->seedExchangeRate(now()->toDateString(), '3.50');

        $resolver = app(CurrencyDisplay::class);
        $this->assertSame('3.500000', $resolver->latestOrDefaultRate());
        $this->assertSame('35.00', $resolver->estimatedIls('10'));
    }

    public function test_service_price_uses_latest_estimate_rate(): void
    {
        $this->seedExchangeRate(now()->toDateString(), '3.20');

        $html = $this->renderMoney(['usd' => '350.00', 'useLatest' => true]);
        $this->assertStringContainsString('$350.00', $html);
        $this->assertStringContainsString('1,120.00 ₪', $html); // 350 × 3.20
    }

    public function test_invoice_list_uses_locked_invoice_rate_not_latest(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '50.00', '3.08'); // total_ils_at_issue = 154.00
        // A newer, different market rate must NOT be used for this issued invoice.
        $this->seedExchangeRate(now()->toDateString(), '4.00');

        $this->get(route('admin.invoices'))
            ->assertOk()
            ->assertSee('$50.00')
            ->assertSee('154.00 ₪')       // 50 × 3.08 (locked)
            ->assertDontSee('200.00 ₪');  // NOT 50 × 4.00 (latest)
    }

    public function test_customer_outstanding_ils_sums_per_document_rates(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '100.00', '3.00'); // 300 ILS
        $this->makeIssuedInvoice($customer, '100.00', '3.20'); // 320 ILS

        $ils = app(CustomerBalanceService::class)->outstandingIlsByDocument($customer);

        // 300 + 320 = 620 — NOT 200 × one blind rate.
        $this->assertSame('620.00', $ils);
    }

    public function test_employee_without_finance_cannot_see_invoice_amounts(): void
    {
        [$employee] = $this->makeStaff();
        $this->makeIssuedInvoice($this->makeCustomer(), '50.00', '3.08');

        // No finance access → redirected away, never sees USD or its ILS line.
        $this->actingAs($employee)->get(route('admin.invoices'))
            ->assertRedirect(route('employee.dashboard'));
    }
}
