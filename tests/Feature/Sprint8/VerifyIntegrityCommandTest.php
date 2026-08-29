<?php

namespace Tests\Feature\Sprint8;

use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class VerifyIntegrityCommandTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
        $this->actingAs($this->makeUser(RoleName::GeneralManager));
    }

    public function test_verify_integrity_passes_on_a_healthy_seeded_database(): void
    {
        $customer = $this->makeCustomer();
        $this->makeIssuedInvoice($customer, '500', '3.20');
        $this->makeIssuedInvoice($customer, '750', '3.30');

        $this->artisan('app:verify-integrity')->assertExitCode(0);
    }

    public function test_verify_integrity_fails_on_a_negative_invoice_balance(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '500', '3.20');

        // Corrupt the data directly, bypassing the model's immutability guard.
        DB::table('invoices')->where('id', $invoice->id)->update(['remaining_usd' => -1]);

        $this->artisan('app:verify-integrity')->assertExitCode(1);
    }
}
