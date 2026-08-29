<?php

namespace Tests\Feature\Sprint7;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Models\Invoice;
use App\Models\PaymentReminderLog;
use App\Models\WhatsAppMessage;
use App\Services\InvoiceService;
use App\Services\PaymentReminderService;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AutomaticReminderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $this->useFakeWhatsApp();
    }

    private function service(): PaymentReminderService
    {
        return app(PaymentReminderService::class);
    }

    /** Invoice dated 2026-08-01 → due 2026-08-31. */
    private function invoice(string $total = '500'): Invoice
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);

        return $this->makeIssuedInvoice($customer, $total, '3.20');
    }

    public function test_before_due_rule_fires_three_days_before(): void
    {
        $this->invoice();
        $result = $this->service()->runRules('2026-08-28'); // 3 days before due
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, WhatsAppMessage::count());
    }

    public function test_due_date_rule_fires_on_due_date(): void
    {
        $this->invoice();
        $result = $this->service()->runRules('2026-08-31');
        $this->assertSame(1, $result['sent']);
    }

    public function test_after_due_rule_fires_seven_days_after(): void
    {
        $this->invoice();
        $result = $this->service()->runRules('2026-09-07');
        $this->assertSame(1, $result['sent']);
    }

    public function test_no_rule_fires_on_an_unrelated_date(): void
    {
        $this->invoice();
        $result = $this->service()->runRules('2026-08-15');
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    public function test_reminder_is_deduped_per_invoice_rule_due_date(): void
    {
        $this->invoice();
        $this->service()->runRules('2026-08-28');
        $this->service()->runRules('2026-08-28'); // second run same day
        $this->assertSame(1, WhatsAppMessage::count());
        $this->assertSame(1, PaymentReminderLog::count());
    }

    public function test_paid_invoice_is_skipped(): void
    {
        $invoice = $this->invoice();
        // Simulate fully paid (remaining 0).
        $invoice->update(['remaining_usd' => '0.00', 'status' => InvoiceStatus::Paid]);
        $result = $this->service()->runRules('2026-08-31');
        $this->assertSame(0, $result['sent']);
    }

    public function test_cancelled_invoice_is_skipped(): void
    {
        $invoice = $this->invoice();
        app(InvoiceService::class)->cancel($invoice, 'إلغاء');
        $result = $this->service()->runRules('2026-08-31');
        $this->assertSame(0, $result['sent']);
    }

    public function test_draft_invoice_is_skipped(): void
    {
        $customer = $this->makeCustomer(['whatsapp_number' => '0591234567']);
        app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => '3.20'],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => '500', 'tax_rate' => 0]],
        );
        $result = $this->service()->runRules('2026-08-31');
        $this->assertSame(0, $result['sent']);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->invoice();
        $result = $this->service()->runRules('2026-08-31', dryRun: true);
        $this->assertSame(1, $result['sent']); // would-send count
        $this->assertSame(0, WhatsAppMessage::count());
        $this->assertSame(0, PaymentReminderLog::count());
    }
}
