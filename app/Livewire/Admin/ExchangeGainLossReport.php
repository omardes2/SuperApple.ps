<?php

namespace App\Livewire\Admin;

use App\Models\Customer;
use App\Models\PaymentAllocation;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('فروقات الصرف')]
class ExchangeGainLossReport extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $customer = '';

    public function mount(): void
    {
        $this->authorize('exchange_gain_loss.view');

        if ($this->from === '') {
            $this->from = now()->startOfYear()->toDateString();
        }
        if ($this->to === '') {
            $this->to = now()->toDateString();
        }
    }

    /**
     * Active allocations on posted payments, joined to payment date/customer for
     * filtering. Reversed allocations are excluded (they carry no realised
     * difference). The exchange difference is realised ILS gain/loss — never
     * sales revenue.
     */
    private function baseQuery()
    {
        return PaymentAllocation::query()
            ->where('payment_allocations.status', PaymentAllocation::STATUS_ACTIVE)
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.status', 'posted')
            ->when($this->from !== '', fn ($q) => $q->whereDate('payments.payment_date', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('payments.payment_date', '<=', $this->to))
            ->when($this->customer !== '', fn ($q) => $q->where('payments.customer_id', $this->customer));
    }

    public function render()
    {
        $rows = (clone $this->baseQuery())
            ->with(['payment.customer', 'invoice'])
            ->orderBy('payments.payment_date')
            ->select('payment_allocations.*')
            ->get();

        $gain = (clone $this->baseQuery())->where('payment_allocations.exchange_difference_ils', '>', 0)
            ->sum('payment_allocations.exchange_difference_ils');
        $loss = (clone $this->baseQuery())->where('payment_allocations.exchange_difference_ils', '<', 0)
            ->sum('payment_allocations.exchange_difference_ils');
        $net = (clone $this->baseQuery())->sum('payment_allocations.exchange_difference_ils');

        return view('livewire.admin.exchange-gain-loss-report', [
            'rows' => $rows,
            'totals' => [
                'gain_ils' => Money::money($gain),
                'loss_ils' => Money::money($loss),
                'net_ils' => Money::money($net),
            ],
            'customers' => Customer::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
