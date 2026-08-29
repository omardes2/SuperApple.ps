<?php

namespace App\Livewire\Admin;

use App\Models\Subscription;
use App\Services\SubscriptionBillingService;
use App\Services\SubscriptionService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('تفاصيل الاشتراك')]
class SubscriptionShow extends Component
{
    public Subscription $subscription;

    public bool $showCancel = false;

    public bool $showResume = false;

    public string $cancelReason = '';

    public string $resumeDate = '';

    public function mount(Subscription $subscription): void
    {
        $this->authorize('view', $subscription);
        $this->subscription = $subscription;
        $this->resumeDate = now()->toDateString();
    }

    public function activate(SubscriptionService $service): void
    {
        $this->authorize('activate', $this->subscription);
        $this->run(fn () => $service->activate($this->subscription), 'تم تفعيل الاشتراك.');
    }

    public function pause(SubscriptionService $service): void
    {
        $this->authorize('pause', $this->subscription);
        $this->run(fn () => $service->pause($this->subscription), 'تم إيقاف الاشتراك مؤقتاً.');
    }

    public function resume(SubscriptionService $service): void
    {
        $this->authorize('resume', $this->subscription);
        $this->validate(['resumeDate' => 'required|date']);
        $this->run(fn () => $service->resume($this->subscription, $this->resumeDate), 'تم استئناف الاشتراك.');
        $this->showResume = false;
    }

    public function cancel(SubscriptionService $service): void
    {
        $this->authorize('cancel', $this->subscription);
        $this->validate(['cancelReason' => 'required|string|min:3']);
        $this->run(fn () => $service->cancel($this->subscription, $this->cancelReason), 'تم إلغاء الاشتراك.');
        $this->showCancel = false;
    }

    public function billNow(SubscriptionBillingService $billing): void
    {
        $this->authorize('bill', $this->subscription);
        try {
            $result = $billing->billOne($this->subscription->id, now()->toDateString());
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }
        $this->subscription->refresh();
        session()->flash('status', 'نتيجة الفوترة: '.($result['message'] ?? $result['outcome']));
    }

    private function run(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }
        $this->subscription->refresh();
        session()->flash('status', $message);
    }

    public function render()
    {
        $this->subscription->load(['customer', 'project', 'items.service', 'billings.invoice']);

        return view('livewire.admin.subscription-show', [
            'sub' => $this->subscription,
            'canPrices' => auth()->user()->can('viewPrices', Subscription::class),
            'canActivate' => auth()->user()->can('activate', $this->subscription),
            'canPause' => auth()->user()->can('pause', $this->subscription),
            'canResume' => auth()->user()->can('resume', $this->subscription),
            'canCancel' => auth()->user()->can('cancel', $this->subscription),
            'canBill' => auth()->user()->can('bill', $this->subscription),
            'mrr' => $this->subscription->isActive() ? $this->subscription->monthlyRecurringRevenue() : null,
        ]);
    }
}
