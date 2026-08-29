<?php

namespace App\Livewire\Admin;

use App\Models\Account;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('دليل الحسابات')]
class ChartOfAccounts extends Component
{
    public function mount(): void
    {
        $this->authorize('chart_accounts.view');
    }

    public function render()
    {
        $accounts = Account::orderBy('code')->get();

        return view('livewire.admin.chart-of-accounts', ['accounts' => $accounts]);
    }
}
