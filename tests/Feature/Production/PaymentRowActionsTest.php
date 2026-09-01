<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Services\FinancialAccountService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The payments list has a per-row actions column (view / edit / delete). Delete
 * works at any status and is accounting-safe: a posted payment is reversed first
 * (invoices restored + GL mirror-reversed to net zero) before the record is
 * removed, so the books stay balanced and integrity holds.
 */
class PaymentRowActionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function draft(): Payment
    {
        $customer = $this->makeCustomer();

        return $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20',
        ]);
    }

    private function posted(): Payment
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);

        return $payment->fresh();
    }

    // ---- UI presence ----

    public function test_actions_column_renders_with_view_icon(): void
    {
        $this->draft();
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->assertOk()
            ->assertSee('إجراءات')
            ->assertSee('مشاهدة الدفعة');
    }

    public function test_draft_row_offers_edit_and_delete(): void
    {
        $this->draft();
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->assertSee('تعديل')
            ->assertSee('حذف الدفعة')
            ->assertSee('سيتم حذف مسودة الدفعة نهائياً');
    }

    public function test_posted_row_offers_delete_action(): void
    {
        $this->posted();
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->assertSee('حذف الدفعة')
            ->assertSee('سيتم عكس القيود المحاسبية لهذه الدفعة');
    }

    // ---- Delete behaviour (accounting-safe) ----

    public function test_draft_payment_can_be_deleted(): void
    {
        $payment = $this->draft();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->call('deletePayment', $payment->id);

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_posted_payment_can_be_deleted_with_reversal(): void
    {
        $payment = $this->posted();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->call('deletePayment', $payment->id);

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_posted_payment_restores_the_invoice(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '100.00', '3.20');
        $cash = $this->makeCashAccount('ILS');
        $payment = $this->service()->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => '320.00', 'exchange_rate' => '3.20', 'account_id' => $cash->id,
        ]);
        $this->service()->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => '100.00']]);
        $this->assertSame('0.00', $invoice->fresh()->remaining_usd);

        $this->service()->delete($payment->fresh(), $this->makeUser(RoleName::GeneralManager));

        // Invoice fully restored, cash movement reversed to zero, record gone.
        $this->assertSame('100.00', $invoice->fresh()->remaining_usd);
        $this->assertSame('0.00', app(FinancialAccountService::class)->balanceIls($cash));
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_posted_payment_leaves_a_balanced_net_zero_ledger(): void
    {
        $payment = $this->posted();
        $this->service()->delete($payment->fresh(), $this->makeUser(RoleName::GeneralManager));

        // The (immutable) journals remain but net to zero — books stay balanced.
        $lines = JournalEntryLine::whereHas('journalEntry',
            fn ($q) => $q->where('source_type', 'payment')->where('source_id', $payment->id))->get();
        $this->assertSame((float) $lines->sum('debit_ils'), (float) $lines->sum('credit_ils'));
    }

    public function test_cancelled_payment_can_be_deleted(): void
    {
        $payment = $this->posted();
        $this->service()->cancel($payment, $this->makeUser(RoleName::GeneralManager), 'خطأ');
        $this->assertTrue($payment->fresh()->isCancelled());

        $this->service()->delete($payment->fresh(), $this->makeUser(RoleName::GeneralManager));
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_payment_keeps_integrity_green(): void
    {
        $payment = $this->posted();
        $this->service()->delete($payment->fresh(), $this->makeUser(RoleName::GeneralManager));

        $this->assertSame(0, Artisan::call('app:verify-integrity'));
    }

    public function test_draft_delete_still_removes_a_draft(): void
    {
        $payment = $this->draft();
        $this->service()->deleteDraft($payment);
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_deleting_draft_creates_an_audit_entry(): void
    {
        $payment = $this->draft();
        $this->service()->deleteDraft($payment);

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment_draft_deleted']);
    }

    public function test_user_without_edit_permission_cannot_delete(): void
    {
        // Accountant lacks nothing here; use a viewer-only custom role instead.
        $role = Role::findOrCreate('عارض دفعات', 'web');
        $role->syncPermissions(['payments.view']);
        $user = $this->makeUser(RoleName::GeneralManager);
        $user->syncRoles(['عارض دفعات']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $payment = $this->draft();

        Livewire::actingAs($user->fresh())->test(PaymentsIndex::class)
            ->call('deletePayment', $payment->id)
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }
}
