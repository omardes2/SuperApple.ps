<?php

namespace App\Livewire\Admin;

use App\Models\Account;
use App\Services\AccountingReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('دفتر الأستاذ العام')]
class GeneralLedgerReport extends Component
{
    #[Url]
    public string $account = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('reports.gl');
        $this->from = $this->from ?: now()->startOfYear()->toDateString();
        $this->to = $this->to ?: now()->toDateString();
    }

    public function render(AccountingReportService $reports)
    {
        $ledger = $this->account !== ''
            ? $reports->generalLedger((int) $this->account, $this->from ?: null, $this->to ?: null)
            : null;

        return view('livewire.admin.general-ledger-report', [
            'ledger' => $ledger,
            'accounts' => Account::postable()->orderBy('code')->get(),
        ]);
    }
}
