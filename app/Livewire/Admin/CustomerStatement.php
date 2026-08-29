<?php

namespace App\Livewire\Admin;

use App\Models\Customer;
use App\Services\CustomerBalanceService;
use App\Services\CustomerStatementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('كشف حساب العميل')]
class CustomerStatement extends Component
{
    public Customer $customer;

    public function mount(Customer $customer): void
    {
        $this->authorize('customer_statements.view');
        $this->customer = $customer;
    }

    public function render(CustomerStatementService $statements, CustomerBalanceService $balances)
    {
        return view('livewire.admin.customer-statement', [
            'statement' => $statements->build($this->customer),
            'balance' => $balances->summary($this->customer),
        ]);
    }
}
