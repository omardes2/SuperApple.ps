<?php

namespace App\Livewire\Admin;

use App\Models\PayrollRun;
use App\Services\PayrollService;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الرواتب')]
class PayrollIndex extends Component
{
    use WithPagination;

    public bool $showCreate = false;

    public int $year;

    public int $month;

    public function mount(): void
    {
        $this->authorize('payroll.view');
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    public function openCreate(): void
    {
        $this->authorize('create', PayrollRun::class);
        $this->showCreate = true;
    }

    public function create(PayrollService $service)
    {
        $this->authorize('create', PayrollRun::class);
        $this->validate([
            'year' => 'required|integer|min:2020|max:2100',
            'month' => 'required|integer|min:1|max:12',
        ]);

        try {
            $run = $service->createRun($this->year, $this->month);
        } catch (\RuntimeException $e) {
            $this->addError('month', $e->getMessage());

            return;
        }

        return redirect()->route('admin.payroll.show', $run);
    }

    public function render()
    {
        $runs = PayrollRun::withCount('items')->latest('year')->latest('month')->paginate(12);

        $current = PayrollRun::where('year', now()->year)->where('month', now()->month)->first();
        $stats = [
            'gross' => Money::money($current?->total_gross_ils ?? 0),
            'deductions' => Money::money($current?->total_deductions_ils ?? 0),
            'net' => Money::money($current?->total_net_ils ?? 0),
            'status' => $current?->status->label() ?? '—',
        ];

        return view('livewire.admin.payroll-index', [
            'runs' => $runs,
            'stats' => $stats,
            'canCreate' => Auth::user()->can('payroll.create'),
        ]);
    }
}
