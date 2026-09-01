<?php

namespace Tests\Feature\Production;

use App\Enums\InvoiceStatus;
use App\Enums\RoleName;
use App\Livewire\Admin\InvoicesIndex;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePdfService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Covers the /admin/invoices actions column: view / edit / print / PDF /
 * WhatsApp / delete-cancel, plus the accounting-safe delete & cancel rules,
 * permissions, and N+1 safety. WhatsApp always uses the offline Fake provider —
 * no real message is ever sent.
 */
class InvoiceActionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function accountant(): User
    {
        $user = $this->makeUser(RoleName::Accountant);
        $this->actingAs($user);

        return $user;
    }

    /** A bare user with only the given permissions (guard web). */
    private function userWith(array $permissions): User
    {
        $user = User::create([
            'name' => 'perm-user', 'email' => str()->random(8).'@test.local',
            'password' => Hash::make('password'), 'is_active' => true, 'locale' => 'ar',
        ]);
        foreach ($permissions as $p) {
            $user->givePermissionTo($p);
        }

        return $user;
    }

    private function draftInvoice(Customer $customer, string $usd = '500', string $rate = '3.20'): Invoice
    {
        return app(InvoiceService::class)->createDraft(
            ['customer_id' => $customer->id, 'invoice_date' => '2026-08-01', 'exchange_rate' => $rate],
            [['item_name' => 'خدمة', 'quantity' => 1, 'unit_price_usd' => $usd, 'tax_rate' => 0]],
        );
    }

    private function payInvoice(Invoice $invoice, string $usd = '100.00'): void
    {
        $cash = $this->cashAccount('USD');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $invoice->customer_id, 'payment_date' => '2026-08-05',
            'payment_currency' => 'USD', 'payment_amount' => $usd, 'exchange_rate' => '3.20',
            'account_id' => $cash->id, 'payment_method' => 'cash',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => $usd]]);
    }

    // ============================================================ Table / UI

    public function test_index_has_actions_column_header(): void
    {
        $this->accountant();
        Livewire::test(InvoicesIndex::class)->assertSee('الإجراءات');
    }

    public function test_draft_shows_edit_and_delete_icons(): void
    {
        $this->accountant();
        $this->draftInvoice($this->makeCustomer());

        Livewire::test(InvoicesIndex::class)
            ->assertSee('تعديل الفاتورة')
            ->assertSee('حذف المسودة');
    }

    public function test_issued_without_payments_is_editable_via_revert(): void
    {
        // An issued invoice with no payments can now be edited the accounting-safe
        // way: reverting it to a draft (reversing its journal), then reissuing.
        $this->accountant();
        $this->makeIssuedInvoice($this->makeCustomer(), '500');

        Livewire::test(InvoicesIndex::class)
            ->assertSee('تعديل الفاتورة (إرجاع لمسودة وعكس القيد)')
            ->assertSee('إلغاء الفاتورة');
    }

    public function test_issued_shows_whatsapp_icon_when_enabled(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        // The row action is a wire:click="openWhatsapp(...)" button (the modal
        // title is always in the DOM, so key on the action, not the label).
        $this->assertStringContainsString('openWhatsapp('.$invoice->id.')', Livewire::test(InvoicesIndex::class)->html());
    }

    public function test_draft_has_no_whatsapp_icon(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $this->draftInvoice($this->makeCustomer());

        $this->assertStringNotContainsString('openWhatsapp(', Livewire::test(InvoicesIndex::class)->html());
    }

    public function test_whatsapp_icon_hidden_when_disabled(): void
    {
        $this->accountant();
        $this->makeIssuedInvoice($this->makeCustomer(), '500');

        $this->assertStringNotContainsString('openWhatsapp(', Livewire::test(InvoicesIndex::class)->html());
    }

    public function test_cancelled_unpaid_invoice_shows_delete_but_no_cancel(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        app(InvoiceService::class)->cancel($invoice, 'خطأ');

        $html = Livewire::test(InvoicesIndex::class)->html();
        // A cancelled invoice cannot be cancelled again…
        $this->assertStringNotContainsString('wire:click="openCancel', $html);
        // …but a cancelled, payment-free invoice CAN be removed from the record.
        $this->assertStringContainsString('wire:click="openDelete', $html);
        $this->assertStringNotContainsString('حذف المسودة', $html); // not the draft label
    }

    public function test_print_and_pdf_icons_present_for_printer(): void
    {
        $this->accountant();
        $this->makeIssuedInvoice($this->makeCustomer(), '500');

        Livewire::test(InvoicesIndex::class)
            ->assertSee('طباعة الفاتورة')
            ->assertSee('تنزيل PDF');
    }

    public function test_index_view_link_present(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        Livewire::test(InvoicesIndex::class)
            ->assertSee('عرض الفاتورة')
            ->assertSee(route('admin.invoices.show', $invoice), false);
    }

    // ============================================================ View / Edit

    public function test_show_page_accessible(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        $this->get(route('admin.invoices.show', $invoice))->assertOk();
    }

    public function test_policy_update_allows_draft_only(): void
    {
        $user = $this->accountant();
        $draft = $this->draftInvoice($this->makeCustomer());
        $issued = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        $this->assertTrue($user->can('update', $draft));
        $this->assertFalse($user->can('update', $issued));
    }

    // ================================================================= Print

    public function test_print_route_ok_and_shows_financials(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500', '3.50');

        $this->get(route('admin.invoices.print', $invoice))
            ->assertOk()
            ->assertSee($invoice->invoice_number)
            ->assertSee('USD')            // official currency on the grand total
            ->assertSee('$500.00')        // total figure
            ->assertDontSee('سعر الصرف')  // no exchange rate on the printed invoice
            ->assertDontSee('3.50');
    }

    public function test_print_forbidden_without_permission(): void
    {
        $invoice = $this->draftInvoice($this->makeCustomer());
        $this->actingAs($this->userWith(['invoices.view']));
        $this->get(route('admin.invoices.print', $invoice))->assertForbidden();
    }

    public function test_draft_print_shows_draft_watermark(): void
    {
        $this->accountant();
        $invoice = $this->draftInvoice($this->makeCustomer());
        $this->get(route('admin.invoices.print', $invoice))->assertSee('مسودة');
    }

    // =================================================================== PDF

    public function test_pdf_route_returns_pdf_with_filename(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        $res = $this->get(route('admin.invoices.pdf', $invoice));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('Invoice-'.$invoice->invoice_number.'.pdf', (string) $res->headers->get('content-disposition'));
    }

    public function test_pdf_bytes_are_a_real_pdf(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        $bytes = app(InvoicePdfService::class)->bytes($invoice);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(800, strlen($bytes));
    }

    public function test_pdf_filename_helper(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        $this->assertSame('Invoice-'.$invoice->invoice_number.'.pdf', app(InvoicePdfService::class)->filename($invoice));
    }

    public function test_pdf_download_is_audited(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        $this->get(route('admin.invoices.pdf', $invoice))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_pdf_downloaded',
            'auditable_id' => $invoice->id,
        ]);
    }

    public function test_pdf_forbidden_without_print_permission(): void
    {
        $invoice = $this->draftInvoice($this->makeCustomer());
        $this->actingAs($this->userWith(['invoices.view']));
        $this->get(route('admin.invoices.pdf', $invoice))->assertForbidden();
    }

    // =============================================================== WhatsApp

    public function test_send_invoice_records_document_via_fake(): void
    {
        $this->accountant();
        $fake = $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '1000', '3.20');

        app(WhatsAppService::class)->sendInvoice($invoice);

        $this->assertCount(1, $fake->documents);
        $doc = $fake->lastDocument();
        $this->assertSame('Invoice-'.$invoice->invoice_number.'.pdf', $doc['filename']);
        $this->assertNotEmpty($doc['phone']);
        $this->assertStringContainsString('.pdf', $doc['document_path']);
    }

    public function test_send_invoice_body_contents(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $customer = $this->makeCustomer(['name' => 'شركة الأمل', 'whatsapp_number' => '0599432037']);
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');

        $body = app(WhatsAppService::class)->invoiceMessageBody($invoice);
        $this->assertStringContainsString('شركة الأمل', $body);
        $this->assertStringContainsString($invoice->invoice_number, $body);
        $this->assertStringContainsString('1000.00 USD', $body);
        $this->assertStringContainsString('3200.00 ILS', $body); // total at invoice rate
        $this->assertStringContainsString('تاريخ الاستحقاق', $body);
    }

    public function test_send_invoice_logs_whatsapp_message_row(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');

        $message = app(WhatsAppService::class)->sendInvoice($invoice);

        $this->assertDatabaseHas('whatsapp_messages', [
            'id' => $message->id,
            'invoice_id' => $invoice->id,
            'document_name' => 'Invoice-'.$invoice->invoice_number.'.pdf',
            'status' => 'sent',
            'provider' => 'fake',
        ]);
    }

    public function test_send_invoice_is_audited(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');

        app(WhatsAppService::class)->sendInvoice($invoice);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice_whatsapp_sent',
            'auditable_id' => $invoice->id,
        ]);
    }

    public function test_resolve_phone_falls_back_to_main_phone(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        // No whatsapp_number → the main phone is used.
        $customer = $this->makeCustomer(['phone' => '0599111222']);
        $this->assertNotEmpty(app(WhatsAppService::class)->resolvePhone($customer));
    }

    public function test_send_invoice_rejects_draft(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $draft = $this->draftInvoice($this->makeCustomer());

        $this->expectException(\RuntimeException::class);
        app(WhatsAppService::class)->sendInvoice($draft);
    }

    public function test_send_invoice_rejects_cancelled(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        app(InvoiceService::class)->cancel($invoice, 'خطأ');

        $this->expectException(\RuntimeException::class);
        app(WhatsAppService::class)->sendInvoice($invoice->fresh());
    }

    public function test_send_invoice_rejects_when_whatsapp_disabled(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        $this->expectException(\RuntimeException::class);
        app(WhatsAppService::class)->sendInvoice($invoice);
    }

    public function test_send_invoice_marks_failed_and_leaves_no_temp_file(): void
    {
        $this->accountant();
        $fake = $this->useFakeWhatsApp();
        $fake->fail('boom');
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');

        try {
            app(WhatsAppService::class)->sendInvoice($invoice);
            $this->fail('Expected send to throw');
        } catch (\RuntimeException $e) {
            // The user-facing message never leaks the provider error.
            $this->assertStringNotContainsString('boom', $e->getMessage());
        }

        $this->assertDatabaseHas('whatsapp_messages', ['invoice_id' => $invoice->id, 'status' => 'failed']);
        $this->assertEmpty(Storage::disk('local')->files('whatsapp-tmp'));
    }

    public function test_send_invoice_leaves_no_temp_file_on_success(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');

        app(WhatsAppService::class)->sendInvoice($invoice);

        $this->assertEmpty(Storage::disk('local')->files('whatsapp-tmp'));
    }

    public function test_livewire_whatsapp_modal_flow(): void
    {
        $this->accountant();
        $fake = $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '750', '3.20');

        Livewire::test(InvoicesIndex::class)
            ->call('openWhatsapp', $invoice->id)
            ->assertSet('showWhatsapp', true)
            ->assertSee('750.00')
            ->call('confirmWhatsapp')
            ->assertSet('showWhatsapp', false);

        $this->assertCount(1, $fake->documents);
        $this->assertDatabaseHas('whatsapp_messages', ['invoice_id' => $invoice->id, 'status' => 'sent']);
    }

    public function test_livewire_whatsapp_preview_notes_prior_sends(): void
    {
        $this->accountant();
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');
        app(WhatsAppService::class)->sendInvoice($invoice); // one prior send

        Livewire::test(InvoicesIndex::class)
            ->call('openWhatsapp', $invoice->id)
            ->assertSee('تم إرسال رسائل سابقة');
    }

    public function test_whatsapp_send_forbidden_without_permission(): void
    {
        $this->useFakeWhatsApp();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(['whatsapp_number' => '0599432037']), '500');
        $this->actingAs($this->userWith(['invoices.view']));

        Livewire::test(InvoicesIndex::class)
            ->call('openWhatsapp', $invoice->id)
            ->assertForbidden();
    }

    // ========================================================= Delete / Cancel

    public function test_delete_draft_removes_invoice_and_items(): void
    {
        $this->accountant();
        $invoice = $this->draftInvoice($this->makeCustomer());
        $itemId = $invoice->items()->first()->id;

        app(InvoiceService::class)->deleteDraft($invoice);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['id' => $itemId]);
    }

    public function test_delete_draft_is_audited(): void
    {
        $this->accountant();
        $invoice = $this->draftInvoice($this->makeCustomer());
        app(InvoiceService::class)->deleteDraft($invoice);

        $this->assertDatabaseHas('audit_logs', ['action' => 'invoice_deleted']);
    }

    public function test_delete_draft_rejects_issued_invoice(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        $this->expectException(\RuntimeException::class);
        app(InvoiceService::class)->deleteDraft($invoice);
    }

    public function test_issued_invoice_is_never_hard_deleted(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        try {
            app(InvoiceService::class)->deleteDraft($invoice);
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    public function test_policy_delete_true_for_clean_draft_and_issued_unpaid(): void
    {
        $user = $this->accountant();
        $draft = $this->draftInvoice($this->makeCustomer());
        $issued = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        // Both are payment-free, so both are deletable (issued has its journal
        // reversed on delete). Invoices tied to a payment are covered as
        // not-deletable in InvoiceSafeDeleteTest.
        $this->assertTrue($user->can('delete', $draft));
        $this->assertTrue($user->can('delete', $issued));
    }

    public function test_livewire_delete_draft_flow(): void
    {
        $this->accountant();
        $invoice = $this->draftInvoice($this->makeCustomer());

        Livewire::test(InvoicesIndex::class)
            ->call('openDelete', $invoice->id)
            ->assertSet('showDelete', true)
            ->call('confirmDelete')
            ->assertSet('showDelete', false);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_cancel_issued_reverses_journal(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500', '3.20');

        app(InvoiceService::class)->cancel($invoice, 'إلغاء تجريبي');

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        // A reversal journal exists for this invoice source.
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'is_reversal' => true,
        ]);
    }

    public function test_cancel_blocked_when_active_payments(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500', '3.20');
        $this->payInvoice($invoice, '100.00');

        $this->expectException(\RuntimeException::class);
        app(InvoiceService::class)->cancel($invoice->fresh(), 'محاولة إلغاء');
    }

    public function test_cannot_cancel_twice(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        app(InvoiceService::class)->cancel($invoice, 'أول');

        $this->expectException(\RuntimeException::class);
        app(InvoiceService::class)->cancel($invoice->fresh(), 'ثاني');
    }

    public function test_livewire_cancel_requires_reason(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        Livewire::test(InvoicesIndex::class)
            ->call('openCancel', $invoice->id)
            ->set('cancelReason', '')
            ->call('confirmCancel')
            ->assertHasErrors('cancelReason');

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_livewire_cancel_flow(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');

        Livewire::test(InvoicesIndex::class)
            ->call('openCancel', $invoice->id)
            ->set('cancelReason', 'خطأ في الإصدار')
            ->call('confirmCancel')
            ->assertSet('showCancel', false);

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_index_shows_disabled_cancel_when_payments_exist(): void
    {
        $this->accountant();
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500', '3.20');
        $this->payInvoice($invoice, '100.00');

        $html = Livewire::test(InvoicesIndex::class)->html();
        $this->assertStringContainsString('يجب إلغاء/عكس الدفعات أولاً', $html);
    }

    // ============================================================ Permissions

    public function test_cancel_forbidden_without_permission(): void
    {
        $invoice = $this->makeIssuedInvoice($this->makeCustomer(), '500');
        $this->actingAs($this->userWith(['invoices.view']));

        Livewire::test(InvoicesIndex::class)
            ->call('openCancel', $invoice->id)
            ->assertForbidden();
    }

    public function test_delete_forbidden_without_edit_permission(): void
    {
        $invoice = $this->draftInvoice($this->makeCustomer());
        $this->actingAs($this->userWith(['invoices.view']));

        Livewire::test(InvoicesIndex::class)
            ->call('openDelete', $invoice->id)
            ->assertForbidden();
    }

    // ============================================================ Performance

    public function test_index_avoids_n_plus_one_on_allocations(): void
    {
        $this->accountant();
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 4; $i++) {
            $this->makeIssuedInvoice($customer, '100');
        }

        $invoices = Livewire::test(InvoicesIndex::class)->viewData('invoices');
        // withCount populates the aggregate on every row — no per-row query.
        foreach ($invoices as $invoice) {
            $this->assertNotNull($invoice->active_allocations_count);
        }
    }
}
