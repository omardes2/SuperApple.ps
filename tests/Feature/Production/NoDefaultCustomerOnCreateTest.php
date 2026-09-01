<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Livewire\Admin\InvoiceShow;
use App\Livewire\Admin\InvoicesIndex;
use App\Livewire\Admin\PaymentCreate;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Creating a new invoice or payment must NOT preselect a default customer: the
 * draft starts customerless and the accountant picks one through the searchable
 * picker. A customer stays required to issue an invoice or post a payment.
 */
class NoDefaultCustomerOnCreateTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_new_invoice_draft_has_no_customer(): void
    {
        // A pre-existing customer must NOT be auto-selected.
        $this->makeCustomer(['name' => 'عميل موجود']);

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)
            ->call('create')
            ->assertRedirect();

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertNull($invoice->customer_id);
        $this->assertTrue($invoice->isDraft());
    }

    public function test_new_payment_opens_create_page_without_persisting(): void
    {
        $this->makeCustomer(['name' => 'عميل موجود']);

        // "+ دفعة" now opens the create page and persists nothing (no default
        // customer, and no abandoned draft).
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentsIndex::class)
            ->call('create')
            ->assertRedirect(route('admin.payments.create'));

        $this->assertSame(0, Payment::count());
    }

    public function test_invoice_can_be_created_even_with_no_customers_at_all(): void
    {
        // Previously this aborted (422) when no customer existed. Now it works.
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)
            ->call('create')
            ->assertRedirect();

        $this->assertNull(Invoice::latest('id')->first()->customer_id);
    }

    public function test_customerless_invoice_shows_the_search_picker(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)->call('create');
        $invoice = Invoice::latest('id')->first();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice])
            ->assertSet('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_new_payment_page_shows_the_search_picker_with_no_customer(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(PaymentCreate::class)
            ->assertSet('customer_id', null)
            ->assertSee('ابحث بالاسم / رقم العميل / واتساب...');
    }

    public function test_issuing_a_customerless_invoice_is_blocked_by_validation(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)->call('create');
        $invoice = Invoice::latest('id')->first();

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoiceShow::class, ['invoice' => $invoice])
            ->call('save')
            ->assertHasErrors('customer_id');

        $this->assertTrue($invoice->fresh()->isDraft());
    }

    public function test_customerless_draft_is_listed_without_crashing(): void
    {
        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)->call('create');

        Livewire::actingAs($this->makeUser(RoleName::GeneralManager))
            ->test(InvoicesIndex::class)
            ->assertOk()
            ->assertSee('بلا عميل');
    }
}
