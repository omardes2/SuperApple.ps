<?php

namespace App\Livewire\Admin;

use App\Services\AccountingReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الميزانية العمومية')]
class BalanceSheetReport extends Component
{
    #[Url]
    public string $asOf = '';

    public function mount(): void
    {
        $this->authorize('reports.balance_sheet');
        $this->asOf = $this->asOf ?: now()->toDateString();
    }

    public function render(AccountingReportService $reports)
    {
        return view('livewire.admin.balance-sheet-report', [
            'report' => $reports->balanceSheet($this->asOf ?: null),
        ]);
    }
}
