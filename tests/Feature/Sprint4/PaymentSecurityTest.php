<?php

namespace Tests\Feature\Sprint4;

use App\Enums\RoleName;
use App\Livewire\Admin\ExchangeGainLossReport;
use App\Livewire\Admin\PaymentsIndex;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    private function aPostedPayment(): Payment
    {
        $this->actingAs($this->makeUser(RoleName::Accountant));
        $customer = $this->makeCustomer();
        $invoice = $this->makeIssuedInvoice($customer, '1000', '3.20');
        $payment = app(PaymentService::class)->createDraft([
            'customer_id' => $customer->id, 'payment_currency' => 'USD',
            'payment_amount' => 1000, 'exchange_rate' => '3.30',
        ]);
        app(PaymentService::class)->post($payment, [['invoice_id' => $invoice->id, 'allocated_usd' => 1000]]);
        auth()->logout();

        return $payment->fresh();
    }

    public function test_employee_cannot_reach_payment_routes(): void
    {
        [$user] = $this->makeStaff();
        $customer = $this->makeCustomer();

        foreach ([
            '/admin/payments',
            route('admin.customers.statement', $customer),
            route('admin.reports.exchange-gain-loss'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertRedirect(route('employee.dashboard'));
        }
    }

    public function test_employee_cannot_open_payment_detail_or_receipt(): void
    {
        $payment = $this->aPostedPayment();
        [$user] = $this->makeStaff();

        $this->actingAs($user)->get(route('admin.payments.show', $payment))->assertRedirect(route('employee.dashboard'));
        $this->actingAs($user)->get(route('admin.payments.receipt', $payment))->assertRedirect(route('employee.dashboard'));
    }

    public function test_employee_cannot_enumerate_payments_via_components(): void
    {
        [$user] = $this->makeStaff();

        Livewire::actingAs($user)->test(PaymentsIndex::class)->assertForbidden();
        Livewire::actingAs($user)->test(ExchangeGainLossReport::class)->assertForbidden();
    }

    public function test_project_manager_and_hr_get_no_payment_permissions(): void
    {
        foreach ([RoleName::ProjectManager, RoleName::HrManager, RoleName::TeamLeader, RoleName::Employee] as $role) {
            $user = $this->makeUser($role);
            foreach ([
                'payments.view', 'payments.create', 'payments.edit', 'payments.post',
                'payments.cancel', 'payments.allocate', 'payments.print',
                'customer_statements.view', 'exchange_gain_loss.view',
            ] as $perm) {
                $this->assertFalse($user->can($perm), "{$role->value} must not have [{$perm}]");
            }
        }
    }

    public function test_accountant_and_gm_can_access_payment_area(): void
    {
        foreach ([RoleName::Accountant, RoleName::GeneralManager] as $role) {
            $user = $this->makeUser($role);
            foreach ([
                'payments.view', 'payments.create', 'payments.post',
                'payments.cancel', 'payments.allocate', 'payments.print',
                'customer_statements.view', 'exchange_gain_loss.view',
            ] as $perm) {
                $this->assertTrue($user->can($perm), "{$role->value} should have [{$perm}]");
            }

            $this->actingAs($user)->get('/admin/payments')->assertOk();
            $this->actingAs($user)->get(route('admin.reports.exchange-gain-loss'))->assertOk();
            auth()->logout();
        }
    }

    public function test_create_and_cancel_are_policy_gated(): void
    {
        $payment = $this->aPostedPayment();
        [$emp] = $this->makeStaff();

        $this->assertFalse($emp->can('create', Payment::class));
        $this->assertFalse($emp->can('view', $payment));
        $this->assertFalse($emp->can('cancel', $payment));

        $accountant = $this->makeUser(RoleName::Accountant);
        $this->assertTrue($accountant->can('create', Payment::class));
        $this->assertTrue($accountant->can('cancel', $payment));
    }
}
