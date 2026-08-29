<?php

namespace App\Livewire\Admin;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Subscription;
use App\Services\SubscriptionMetricsService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('الاشتراكات')]
class SubscriptionsIndex extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public string $search = '';

    public bool $showCreate = false;

    // Create form
    public ?int $customer_id = null;

    public ?int $project_id = null;

    public string $name = '';

    public string $description = '';

    public string $billing_cycle = 'monthly';

    public int $billing_interval = 1;

    public string $start_date = '';

    public string $end_date = '';

    public string $payment_terms_days = '';

    public bool $auto_generate_invoice = true;

    public bool $auto_issue_invoice = false;

    public string $terms = '';

    /** @var list<array<string,mixed>> */
    public array $items = [];

    public function mount(): void
    {
        $this->authorize('subscriptions.view');
        $this->start_date = now()->toDateString();
    }

    public function openCreate(): void
    {
        $this->authorize('create', Subscription::class);
        $this->reset(['customer_id', 'project_id', 'name', 'description', 'billing_cycle', 'billing_interval', 'end_date', 'payment_terms_days', 'terms']);
        $this->billing_cycle = 'monthly';
        $this->billing_interval = 1;
        $this->auto_generate_invoice = true;
        $this->auto_issue_invoice = false;
        $this->start_date = now()->toDateString();
        $this->items = [['item_name' => '', 'quantity' => 1, 'unit_price_usd' => '0', 'tax_rate' => 0]];
        $this->showCreate = true;
    }

    public function addItem(): void
    {
        $this->items[] = ['item_name' => '', 'quantity' => 1, 'unit_price_usd' => '0', 'tax_rate' => 0];
    }

    public function removeItem(int $i): void
    {
        unset($this->items[$i]);
        $this->items = array_values($this->items);
    }

    public function save(SubscriptionService $service): void
    {
        $this->authorize('create', Subscription::class);
        $this->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'name' => 'required|string|max:200',
            'billing_cycle' => 'required|in:weekly,monthly,quarterly,semi_annual,yearly,custom',
            'billing_interval' => 'required|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'payment_terms_days' => 'nullable|integer|min:0',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:200',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_price_usd' => 'required|numeric|gte:0',
        ]);

        $service->create([
            'customer_id' => $this->customer_id,
            'project_id' => $this->project_id ?: null,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'billing_cycle' => $this->billing_cycle,
            'billing_interval' => $this->billing_interval,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date ?: null,
            'payment_terms_days' => $this->payment_terms_days !== '' ? (int) $this->payment_terms_days : null,
            'auto_generate_invoice' => $this->auto_generate_invoice,
            'auto_issue_invoice' => $this->auto_issue_invoice,
            'terms' => $this->terms ?: null,
        ], $this->items);

        $this->showCreate = false;
        session()->flash('status', 'تم إنشاء الاشتراك (مسودة). فعّله لبدء الفوترة.');
    }

    public function render(SubscriptionMetricsService $metrics)
    {
        $query = Subscription::with('customer')
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('subscription_number', 'like', "%{$this->search}%")))
            ->latest('id');

        $canPrices = auth()->user()->can('viewPrices', Subscription::class);

        return view('livewire.admin.subscriptions-index', [
            'subscriptions' => $query->paginate(15),
            'statuses' => SubscriptionStatus::options(),
            'cycles' => BillingCycle::options(),
            'customers' => Customer::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'summary' => $metrics->summary(),
            'canCreate' => Auth::user()->can('subscriptions.create'),
            'canPrices' => $canPrices,
            'canReports' => Auth::user()->can('subscriptions.reports'),
        ]);
    }
}
