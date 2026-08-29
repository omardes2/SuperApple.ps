<?php

namespace App\Livewire\Admin;

use App\Services\ReportsService;
use App\Services\SubscriptionMetricsService;
use App\Support\Format;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تقارير الاشتراكات')]
class SubscriptionsReport extends Component
{
    public function mount(): void
    {
        $this->authorize('reports.subscriptions');
    }

    public function render(ReportsService $reports, SubscriptionMetricsService $metrics)
    {
        return view('livewire.admin.subscriptions-report', [
            'a' => $reports->subscriptionAnalytics(),
            'upcoming' => $metrics->upcomingBillings(30),
            'fmt' => Format::class,
        ]);
    }
}
