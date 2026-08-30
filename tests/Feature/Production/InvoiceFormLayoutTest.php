<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Database\Seeders\WhatsAppSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Layout/UX redesign of the invoice page. These guard the presence of every
 * field, section and action after the two-column restructure — the underlying
 * InvoiceService, accounting, permissions and status workflow are untouched.
 */
class InvoiceFormLayoutTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->seed(WhatsAppSeeder::class);
    }

    private function draft(): Invoice
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));

        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $this->makeCustomer(['whatsapp_number' => '0599432037'])->id, 'exchange_rate' => '3.08'],
            [['item_name' => 'تصميم هوية', 'quantity' => 1, 'unit_price_usd' => 100, 'tax_rate' => 0]]);
    }

    public function test_draft_invoice_page_renders(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])->assertOk();
    }

    public function test_issued_invoice_page_renders(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500', '3.20');
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice])->assertOk();
    }

    public function test_draft_shows_all_invoice_data_fields(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('بيانات الفاتورة')
            ->assertSee('العميل')
            ->assertSee('تاريخ الفاتورة')
            ->assertSee('تاريخ الاستحقاق')
            ->assertSee('سعر صرف الفاتورة');
    }

    public function test_draft_shows_items_section_and_add_action(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('بنود الفاتورة')
            ->assertSee('إضافة بند')
            ->assertSee('وصف البند');
    }

    public function test_add_line_action_works(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertSet('lines', fn ($lines) => count($lines) === 1)
            ->call('addLine')
            ->assertSet('lines', fn ($lines) => count($lines) === 2);
    }

    public function test_summary_card_and_total_render(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('ملخص الفاتورة')
            ->assertSee('الإجمالي')
            ->assertSee('$100.00')
            ->assertSee('308.00 ₪'); // 100 × 3.08 at the invoice's own rate
    }

    public function test_invoice_info_and_timeline_cards_render(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('معلومات الفاتورة')
            ->assertSee('سجل الفاتورة')
            ->assertSee('أُنشئت');
    }

    public function test_draft_shows_save_and_issue_and_print_actions(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('حفظ المسودة')
            ->assertSee('إصدار الفاتورة')
            ->assertSee('طباعة');
    }

    public function test_issued_invoice_is_readonly_with_cancel_action(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500', '3.20');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice])
            ->assertOk()
            ->assertSee('بنود الفاتورة')
            ->assertSee('إلغاء الفاتورة')
            ->assertDontSee('حفظ المسودة'); // locked — no draft editor
    }

    public function test_whatsapp_log_section_renders(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertSee('سجل رسائل واتساب');
    }

    public function test_save_draft_still_persists_changes(): void
    {
        $invoice = $this->draft();

        Livewire::test(InvoiceShow::class, ['invoice' => $invoice])
            ->set('lines.0.unit_price_usd', 250)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('250.00', $invoice->fresh()->total_usd);
    }

    public function test_no_project_field_is_present(): void
    {
        Livewire::test(InvoiceShow::class, ['invoice' => $this->draft()])
            ->assertOk()
            ->assertDontSee('المشروع')
            ->assertDontSee('مشروع');
    }

    public function test_unauthorized_employee_cannot_view_invoice(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500', '3.20');
        [$employee] = $this->makeStaff();

        Livewire::actingAs($employee)->test(InvoiceShow::class, ['invoice' => $invoice])->assertForbidden();
    }
}
