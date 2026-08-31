<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The payments list has a per-row actions column (view / edit / delete). Delete
 * is accounting-safe: only a draft (which has no GL journal) can be removed;
 * a posted payment must be corrected through cancellation and can never be
 * deleted, so its ledger history is preserved.
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
            ->assertSee('حذف المسودة');
    }

    public function test_posted_row_shows_delete_disabled_hint(): void
    {
        $this->posted();
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->assertSee('لا يمكن حذف دفعة مُرحّلة — ألغِها (عكس القيد) من صفحة الدفعة')
            ->assertDontSee('حذف المسودة');
    }

    // ---- Delete behaviour (accounting-safe) ----

    public function test_draft_payment_can_be_deleted(): void
    {
        $payment = $this->draft();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->call('deletePayment', $payment->id);

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_posted_payment_cannot_be_deleted_via_component(): void
    {
        $payment = $this->posted();

        // The policy forbids it → Livewire authorization error, nothing removed.
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))->test(PaymentsIndex::class)
            ->call('deletePayment', $payment->id)
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }

    public function test_service_refuses_to_delete_a_posted_payment(): void
    {
        $payment = $this->posted();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن حذف دفعة مُرحّلة');
        $this->service()->deleteDraft($payment);
    }

    public function test_deleting_posted_payment_preserves_its_journal(): void
    {
        $payment = $this->posted();
        $journalsBefore = JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->count();
        $this->assertGreaterThan(0, $journalsBefore);

        try {
            $this->service()->deleteDraft($payment);
        } catch (RuntimeException) {
            // expected
        }

        // Payment and its GL history untouched.
        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
        $this->assertSame(
            $journalsBefore,
            JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->count()
        );
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
