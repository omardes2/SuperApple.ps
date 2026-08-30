<?php

namespace Tests\Feature\Sprint4;

use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class SmokeRenderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function postedPaymentFor(Customer $customer): Payment
    {
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('ILS')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'ILS',
            'payment_amount' => 3300, 'exchange_rate' => '3.30',
        ]);

        return app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);
    }

    public function test_payment_pages_render_for_accountant(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);

        $customer = $this->makeCustomer();
        $payment = $this->postedPaymentFor($customer);

        foreach (['/admin/payments', route('admin.reports.exchange-gain-loss')] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('admin.payments.show', $payment))->assertOk()->assertSee($payment->payment_number);
        $this->get(route('admin.customers.statement', $customer))->assertOk()->assertSee($customer->name);
    }

    public function test_receipt_renders_and_hides_nothing_internal(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);

        $payment = $this->postedPaymentFor($this->makeCustomer());

        $this->get(route('admin.payments.receipt', $payment))
            ->assertOk()
            ->assertSee($payment->payment_number)
            ->assertSee('إيصال قبض')
            ->assertSee('USD');
    }

    public function test_customer_profile_shows_payments_tab_for_accountant(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $customer = $this->makeCustomer();

        $this->actingAs($accountant)->get(route('admin.customers.show', $customer))
            ->assertOk()
            ->assertSee('الدفعات')
            ->assertSee('صافي الرصيد');
    }

    public function test_dashboard_shows_financial_cards_for_accountant_only(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant)->get('/admin')->assertOk()->assertSee('النظرة المالية');
        auth()->logout();

        // Employees must never see the financial section.
        [$emp] = $this->makeStaff();
        $this->actingAs($emp)->get('/employee')->assertOk()->assertDontSee('النظرة المالية');
    }

    public function test_new_payment_draft_flow_renders(): void
    {
        $accountant = $this->makeUser(RoleName::Accountant);
        $this->actingAs($accountant);
        $customer = $this->makeCustomer();

        $draft = app(PaymentService::class)->createDraft(['account_id' => $this->cashAccount('USD')->id,
            'customer_id' => $customer->id, 'payment_currency' => 'USD', 'payment_amount' => 0,
        ]);

        $this->get(route('admin.payments.show', $draft))
            ->assertOk()
            ->assertSee('تخصيص الدفعة على الفواتير');
    }
}
