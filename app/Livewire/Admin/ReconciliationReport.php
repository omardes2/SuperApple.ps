<?php

namespace App\Livewire\Admin;

use App\Services\ReconciliationService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير المطابقة')]
class ReconciliationReport extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.reconciliation');
    }

    public function render(ReconciliationService $recon)
    {
        return view('livewire.admin.reconciliation-report', ['rows' => $recon->all()]);
    }
}
