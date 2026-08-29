<?php

namespace App\Livewire\Admin;

use App\Models\WhatsAppMessage;
use App\Services\ReportsService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير المراسلات')]
class WhatsAppReport extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.whatsapp');
    }

    public function render(ReportsService $reports)
    {
        return view('livewire.admin.whatsapp-report', [
            'a' => $reports->whatsappAnalytics(),
            'recentFailed' => WhatsAppMessage::failed()->with('customer')->latest('id')->limit(15)->get(),
        ]);
    }
}
