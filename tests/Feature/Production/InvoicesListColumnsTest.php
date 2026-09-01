<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The invoices list drops the exchange-rate column in favour of a remaining
 * balance column (المبلغ المتبقي), so collectors see what is still owed at a
 * glance.
 */
class InvoicesListColumnsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_invoices_list_shows_remaining_column_not_exchange_rate(): void
    {
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '10.00', '3.20'); // remaining 10.00

        $this->get(route('admin.invoices'))
            ->assertOk()
            ->assertSee('المتبقي')       // remaining-balance column (renamed for density)
            ->assertDontSee('سعر الصرف')
            ->assertSee('$10.00'); // the remaining balance shown
    }
}
