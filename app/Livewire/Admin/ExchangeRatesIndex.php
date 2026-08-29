<?php

namespace App\Livewire\Admin;

use App\Enums\ExchangeRateSource;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('أسعار الصرف')]
class ExchangeRatesIndex extends Component
{
    use WithPagination;

    public bool $showForm = false;

    public string $rate_date = '';

    public string $rate = '';

    public string $source = 'manual';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('exchange_rates.view');
        $this->rate_date = now()->toDateString();
    }

    public function create(): void
    {
        $this->authorize('exchange_rates.manage');
        $this->reset(['rate', 'notes']);
        $this->rate_date = now()->toDateString();
        $this->source = 'manual';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(ExchangeRateService $service): void
    {
        $this->authorize('exchange_rates.manage');

        $data = $this->validate([
            'rate_date' => 'required|date',
            'rate' => 'required|numeric|gt:0',
            'source' => 'required|in:manual,api,bank,other',
            'notes' => 'nullable|string|max:255',
        ]);

        $service->set($data);

        $this->showForm = false;
        session()->flash('status', 'تم حفظ سعر الصرف. (لا يؤثر على الفواتير الصادرة سابقاً)');
    }

    public function render()
    {
        return view('livewire.admin.exchange-rates-index', [
            'rates' => ExchangeRate::usdIls()->orderByDesc('rate_date')->paginate(20),
            'sourceOptions' => ExchangeRateSource::options(),
        ]);
    }
}
