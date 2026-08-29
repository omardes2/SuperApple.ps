<?php

namespace App\Livewire\Admin;

use App\Services\AccountingReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('ميزان المراجعة')]
class TrialBalanceReport extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('reports.trial_balance');
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function render(AccountingReportService $reports)
    {
        return view('livewire.admin.trial-balance-report', [
            'report' => $reports->trialBalance($this->from ?: null, $this->to ?: null),
        ]);
    }
}
