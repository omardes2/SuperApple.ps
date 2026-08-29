<?php

namespace Tests\Feature\Sprint3;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Exceptions\IssuedInvoiceImmutableException;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Service;
use App\Services\ExchangeRateService;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use App\Services\QuotationToInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FinancialCoreTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function rate(string $date, string $rate): ExchangeRate
    {
        return app(ExchangeRateService::class)->set(['rate_date' => $date, 'rate' => $rate]);
    }

    public function test_exchange_rate_precision_preserved_and_positive_enforced(): void
    {
        $rate = $this->rate('2026-08-29', '3.284567');
        $this->assertSame('3.284567', $rate->rate);

        $this->expectException(RuntimeException::class);
        $this->rate('2026-08-30', '0');
    }

    public function test_same_day_rate_updates_instead_of_duplicating(): void
    {
        $this->rate('2026-08-29', '3.28');
        $this->rate('2026-08-29', '3.29');

        $this->assertSame(1, ExchangeRate::whereDate('rate_date', '2026-08-29')->count());
        $this->assertSame('3.290000', ExchangeRate::first()->rate);
    }

    public function test_quotation_line_and_totals_are_recomputed_on_backend(): void
    {
        $customer = $this->makeCustomer();
        $quotation = app(QuotationService::class)->createDraft(
            ['customer_id' => $customer->id],
            [[
                'item_name' => 'تصميم',
                'quantity' => 2,
                'unit_price_usd' => 500,
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'tax_rate' => 16,
                // Bogus totals from the "frontend" must be ignored.
                'line_total_usd' => 999999,
            ]],
        );

        $item = $quotation->items->first();
        // gross 1000, -10% = 100 discount → taxable 900, tax 16% = 144, total 1044.
        $this->assertSame('1000.00', $item->line_subtotal_usd);
        $this->assertSame('100.00', $item->discount_usd);
        $this->assertSame('144.00', $item->tax_usd);
        $this->assertSame('1044.00', $item->line_total_usd);
        $this->assertSame('1044.00', $quotation->total_usd);
    }

    public function test_fixed_discount_and_zero_tax(): void
    {
        $customer = $this->makeCustomer();
        $quotation = app(QuotationService::class)->createDraft(
            ['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 300, 'discount_type' => 'fixed', 'discount_value' => 50, 'tax_rate' => 0]],
        );

        $item = $quotation->items->first();
        $this->assertSame('50.00', $item->discount_usd);
        $this->assertSame('0.00', $item->tax_usd);
        $this->assertSame('250.00', $item->line_total_usd);
    }

    public function test_service_price_is_snapshotted_into_quotation_item(): void
    {
        $customer = $this->makeCustomer();
        $service = Service::create([
            'service_code' => 'SRV-X', 'name' => 'تصميم شعار', 'service_type' => 'one_time',
            'default_price_usd' => 500, 'tax_rate' => 16, 'is_active' => true,
        ]);

        $quotation = app(QuotationService::class)->createDraft(
            ['customer_id' => $customer->id],
            [['service_id' => $service->id, 'quantity' => 1]], // name/price/tax pulled from catalog
        );
        $item = $quotation->items->first();
        $this->assertSame('تصميم شعار', $item->item_name);
        $this->assertSame('500.0000', $item->unit_price_usd);

        // Later price change must NOT alter the historical quotation.
        $service->update(['default_price_usd' => 600]);
        $this->assertSame('500.0000', $quotation->items()->first()->unit_price_usd);
    }

    public function test_full_invoice_issue_locks_and_snapshots_ils(): void
    {
        $this->rate('2026-08-29', '3.28');
        $customer = $this->makeCustomer();

        $invoice = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-29', 'exchange_rate' => '3.28'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 2000, 'tax_rate' => 0]],
        );

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('2000.00', $invoice->total_usd);

        app(InvoiceService::class)->issue($invoice);
        $invoice->refresh();

        // The mandatory example: $2,000 × 3.28 = 6,560 ILS.
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('2000.00', $invoice->total_usd);
        $this->assertSame('3.280000', $invoice->exchange_rate);
        $this->assertSame('6560.00', $invoice->total_ils_at_issue);
        $this->assertSame('0.00', $invoice->paid_usd_equivalent);
        $this->assertSame('2000.00', $invoice->remaining_usd);
        $this->assertNotNull($invoice->issued_at);
        $this->assertNotNull($invoice->customer_snapshot);
    }

    public function test_issued_invoice_is_immutable_against_direct_writes(): void
    {
        $this->rate('2026-08-29', '3.28');
        $invoice = $this->issuedInvoice();

        $this->expectException(IssuedInvoiceImmutableException::class);
        $invoice->update(['total_usd' => 999]);
    }

    public function test_issued_invoice_exchange_rate_cannot_change(): void
    {
        $this->rate('2026-08-29', '3.28');
        $invoice = $this->issuedInvoice();

        $this->expectException(IssuedInvoiceImmutableException::class);
        $invoice->update(['exchange_rate' => '3.99']);
    }

    public function test_changing_global_rate_after_issue_does_not_change_snapshot(): void
    {
        $this->rate('2026-08-29', '3.28');
        $invoice = $this->issuedInvoice();

        // Correct the rate table afterwards.
        $this->rate('2026-08-29', '3.35');

        $this->assertSame('3.280000', $invoice->fresh()->exchange_rate);
        $this->assertSame('6560.00', $invoice->fresh()->total_ils_at_issue);
    }

    public function test_invoice_cannot_issue_without_items_or_rate(): void
    {
        $customer = $this->makeCustomer();

        $noItems = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-29', 'exchange_rate' => '3.28'], []);
        try {
            app(InvoiceService::class)->issue($noItems);
            $this->fail('should reject issuing without items');
        } catch (RuntimeException $e) {
            $this->assertSame(InvoiceStatus::Draft, $noItems->fresh()->status);
        }

        $noRate = app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-29', 'exchange_rate' => null],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->issue($noRate);
    }

    public function test_conversion_copies_snapshot_and_prevents_duplicates(): void
    {
        $customer = $this->makeCustomer();
        $quotation = app(QuotationService::class)->createDraft(
            ['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 800, 'tax_rate' => 16]],
        );
        app(QuotationService::class)->send($quotation);
        app(QuotationService::class)->accept($quotation->fresh());

        $invoice = app(QuotationToInvoiceService::class)->convert($quotation->fresh());
        $this->assertSame($quotation->id, $invoice->quotation_id);
        $this->assertSame('928.00', $invoice->total_usd); // 800 + 16%
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame($invoice->id, $quotation->fresh()->converted_invoice_id);

        // Second conversion must be blocked.
        $this->expectException(RuntimeException::class);
        app(QuotationToInvoiceService::class)->convert($quotation->fresh());
    }

    public function test_only_accepted_quotation_converts(): void
    {
        $customer = $this->makeCustomer();
        $quotation = app(QuotationService::class)->createDraft(['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);

        // Still Draft.
        $this->expectException(RuntimeException::class);
        app(QuotationToInvoiceService::class)->convert($quotation);
    }

    public function test_changing_quotation_later_does_not_mutate_invoice(): void
    {
        $customer = $this->makeCustomer();
        $q = app(QuotationService::class)->createDraft(['customer_id' => $customer->id],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 100]]);
        app(QuotationService::class)->send($q);
        app(QuotationService::class)->accept($q->fresh());
        $invoice = app(QuotationToInvoiceService::class)->convert($q->fresh());

        // Revise the quotation into a new draft and change it.
        $revision = app(QuotationService::class)->duplicateAsRevision($q->fresh());
        app(QuotationService::class)->updateDraft($revision, [],
            [['item_name' => 'مختلف', 'quantity' => 5, 'unit_price_usd' => 999]]);

        $this->assertSame('100.00', $invoice->fresh()->total_usd);
        $this->assertSame(1, $invoice->items()->count());
    }

    public function test_invoice_cancellation_requires_reason_and_keeps_record(): void
    {
        $this->rate('2026-08-29', '3.28');
        $invoice = $this->issuedInvoice();
        $number = $invoice->invoice_number;

        try {
            app(InvoiceService::class)->cancel($invoice, '');
            $this->fail('should require reason');
        } catch (RuntimeException $e) {
        }

        app(InvoiceService::class)->cancel($invoice->fresh(), 'خطأ في المبلغ');
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Cancelled, $invoice->status);
        $this->assertSame('خطأ في المبلغ', $invoice->cancellation_reason);
        $this->assertDatabaseHas('invoices', ['invoice_number' => $number]);
    }

    public function test_overdue_is_computed_not_stored(): void
    {
        $this->rate('2026-08-29', '3.28');
        // Invoice dated 40 days ago → default due date (issue+30) is in the past.
        $invoice = $this->issuedInvoice(['invoice_date' => now()->subDays(40)->toDateString()]);

        // Overdue is computed on the fly; no is_overdue column exists.
        $this->assertFalse(Schema::hasColumn('invoices', 'is_overdue'));
        $this->assertTrue($invoice->fresh()->isOverdue());
        $this->assertSame(InvoiceStatus::Overdue, $invoice->fresh()->effectiveStatus());
    }

    private function issuedInvoice(array $overrides = []): Invoice
    {
        $customer = $this->makeCustomer();
        $invoice = app(InvoiceService::class)->createDraft(array_merge([
            'customer_id' => $customer->id, 'invoice_date' => '2026-08-29', 'exchange_rate' => '3.28',
        ], $overrides), [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => 2000, 'tax_rate' => 0]]);

        return app(InvoiceService::class)->issue($invoice);
    }
}
