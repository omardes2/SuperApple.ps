<?php

namespace App\Livewire\Admin;

use App\Services\AccountingReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('قائمة الدخل')]
class ProfitLossReport extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('reports.profit_loss');
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function render(AccountingReportService $reports)
    {
        return view('livewire.admin.profit-loss-report', [
            'report' => $reports->profitAndLoss($this->from ?: null, $this->to ?: null),
        ]);
    }
}
