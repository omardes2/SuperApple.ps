<?php

namespace App\Livewire\Admin;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Services\CustomerBalanceService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\CustomerService;
use App\Services\PaymentReminderService;
use App\Services\ReportsService;
use App\Support\Money;
use App\Support\TemplateRenderer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('العملاء')]
class CustomersIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** Active filter: '' = all, '1' = active, '0' = inactive. */
    #[Url]
    public string $active = '';

    /** Balance filter: '' = all, 'due' = outstanding > 0, 'zero' = no outstanding. */
    #[Url]
    public string $balance = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    // Simplified customer form — only three operational fields. The remaining
    // columns (contact_person, phone, city, address, tax_number, category,
    // source, status) stay in the database as legacy but are no longer edited
    // here. WhatsApp is the primary contact channel.
    public ?string $customer_number = null;

    public string $name = '';

    public string $whatsapp_number = '';

    public string $notes = '';

    // ---- Optional opening balance (finance users only, on create) ----
    public bool $showOpeningBalance = false;

    public string $obType = CustomerOpeningBalance::TYPE_DEBIT;

    public string $obAmountUsd = '';

    public string $obRate = '';

    public string $obDate = '';

    public string $obNotes = '';

    // WhatsApp balance-reminder modal (opened from the row actions).
    public bool $showReminder = false;

    public ?int $reminderCustomerId = null;

    public string $reminderBody = '';

    public function mount(): void
    {
        $this->authorize('customers.view');
        $this->obDate = now()->toDateString();
    }

    public function canManageOpeningBalance(): bool
    {
        return auth()->user()->can('customers.opening_balance.manage');
    }

    public function getObIlsPreviewProperty(): string
    {
        $rate = (float) ($this->obRate ?: 0);

        return $rate > 0 && $this->obAmountUsd !== '' ? Money::convertUsdToIls($this->obAmountUsd ?: 0, $this->obRate) : '0.00';
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'active', 'balance'], true)) {
            $this->resetPage();
        }
    }

    /** Whether the current user may see any financial (outstanding) figures. */
    private function canViewBalance(): bool
    {
        return auth()->user()->can('payments.view');
    }

    public function create(): void
    {
        $this->authorize('customers.create');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('customers.edit');
        $c = Customer::findOrFail($id);
        $this->editingId = $c->id;
        $this->customer_number = $c->customer_number;
        $this->name = $c->name;
        $this->whatsapp_number = (string) $c->whatsapp_number;
        $this->notes = (string) $c->notes;
        $this->showForm = true;
    }

    public function save(CustomerService $service): void
    {
        $this->authorize($this->editingId ? 'customers.edit' : 'customers.create');

        $validated = $this->validate([
            'name' => 'required|string|max:150',
            // Lenient rule: accepts local (0599432037) and international
            // (972599432037, +972 59 943 2037) Palestinian formats.
            'whatsapp_number' => ['required', 'string', 'max:40', 'regex:/^[0-9+()\-\s]{7,25}$/'],
            'notes' => 'nullable|string|max:2000',
        ], [], [
            'name' => 'الاسم',
            'whatsapp_number' => 'رقم واتساب',
            'notes' => 'ملاحظات',
        ]);

        $notes = ($validated['notes'] ?? '') !== '' ? $validated['notes'] : null;

        // Validate the optional opening-balance section (finance users, create).
        $withOpeningBalance = ! $this->editingId && $this->showOpeningBalance && $this->canManageOpeningBalance();
        if ($withOpeningBalance) {
            $this->validate([
                'obType' => 'required|in:debit,credit',
                'obAmountUsd' => 'required|numeric|gt:0',
                'obRate' => 'required|numeric|gt:0',
                'obDate' => 'required|date',
                'obNotes' => 'nullable|string|max:2000',
            ], [], [
                'obAmountUsd' => 'مبلغ الرصيد', 'obRate' => 'سعر الصرف', 'obDate' => 'تاريخ الرصيد',
            ]);
        }

        if ($this->editingId) {
            // Update only the three editable fields; legacy columns are untouched.
            $service->update(Customer::findOrFail($this->editingId), [
                'name' => $validated['name'],
                'whatsapp_number' => $validated['whatsapp_number'],
                'notes' => $notes,
            ]);
            session()->flash('status', 'تم تحديث العميل.');
        } else {
            // New customers are active by default; the internal number is
            // generated by the service. No status/lead selection at creation.
            $customer = $service->create([
                'name' => $validated['name'],
                'whatsapp_number' => $validated['whatsapp_number'],
                'notes' => $notes,
                'status' => CustomerStatus::Active->value,
                'is_active' => true,
            ]);

            if ($withOpeningBalance) {
                app(CustomerOpeningBalanceService::class)->create($customer, [
                    'type' => $this->obType,
                    'amount_usd' => $this->obAmountUsd,
                    'exchange_rate' => $this->obRate,
                    'balance_date' => $this->obDate,
                    'notes' => $this->obNotes ?: null,
                ]);
            }
            session()->flash('status', 'تم إضافة العميل.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function archive(int $id, CustomerService $service): void
    {
        $this->authorize('customers.archive');
        $service->archive(Customer::findOrFail($id));
        session()->flash('status', 'تم تعطيل العميل.');
    }

    public function restore(int $id, CustomerService $service): void
    {
        // Re-activate a disabled customer. Uses the existing service method; the
        // customer lifecycle itself is unchanged.
        $this->authorize('customers.archive');
        $service->restore(Customer::findOrFail($id));
        session()->flash('status', 'تم تفعيل العميل.');
    }

    /** Open the WhatsApp balance-reminder modal prefilled with an editable body. */
    public function openReminder(int $id, PaymentReminderService $reminders): void
    {
        $this->authorize('whatsapp.send');
        $this->reminderCustomerId = $id;
        $customer = Customer::findOrFail($id);
        $template = $reminders->defaultManualTemplate();
        try {
            $this->reminderBody = $template
                ? TemplateRenderer::render($template->body, $reminders->balanceVariables($customer))
                : $reminders->defaultManualBody($customer);
        } catch (\Throwable $e) {
            $this->reminderBody = $reminders->defaultManualBody($customer);
        }
        $this->resetErrorBag();
        $this->showReminder = true;
    }

    public function sendReminder(PaymentReminderService $reminders): void
    {
        $this->authorize('whatsapp.send');
        $this->validate(['reminderBody' => 'required|string|min:2']);
        try {
            $reminders->sendManualReminder(Customer::findOrFail($this->reminderCustomerId), $this->reminderBody);
        } catch (\Throwable $e) {
            $this->addError('reminder', $e->getMessage());

            return;
        }
        $this->showReminder = false;
        session()->flash('status', 'تم إرسال التذكير عبر واتساب.');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'customer_number', 'name', 'whatsapp_number', 'notes',
            'showOpeningBalance', 'obType', 'obAmountUsd', 'obRate', 'obNotes']);
        $this->obDate = now()->toDateString();
        $this->resetErrorBag();
    }

    public function render()
    {
        $canViewBalance = $this->canViewBalance();

        // "Has a balance" = at least one issued (not draft/cancelled) invoice
        // with remaining_usd > 0. Because remaining_usd is never negative, this
        // is equivalent to outstanding_usd > 0 and runs as a single EXISTS
        // sub-query (no per-row PHP queries). The balance filter is only honoured
        // for finance users, so it can never leak financial state.
        // "Has a balance" = an open invoice OR a debit opening balance with a
        // remaining amount. Both are Accounts-Receivable documents.
        $hasOutstanding = fn ($q) => $q
            ->whereHas('invoices', fn ($i) => $i->issued()->where('remaining_usd', '>', 0))
            ->orWhereHas('openingBalances', fn ($o) => $o->posted()->debit()->where('remaining_usd', '>', 0));
        $noOutstanding = fn ($q) => $q
            ->whereDoesntHave('invoices', fn ($i) => $i->issued()->where('remaining_usd', '>', 0))
            ->whereDoesntHave('openingBalances', fn ($o) => $o->posted()->debit()->where('remaining_usd', '>', 0));

        $customers = Customer::query()
            ->withCount('tasks')
            // Date of the last OUTBOUND WhatsApp message to each customer, from
            // any source (invoice send or a manual/balance reminder). One
            // aggregate sub-query — no per-row lookups.
            ->withMax(['whatsappMessages as last_whatsapp_at' => fn ($q) => $q->outbound()], 'created_at')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('customer_number', 'like', "%{$this->search}%")
                ->orWhere('whatsapp_number', 'like', "%{$this->search}%")))
            ->when($this->active !== '', fn ($q) => $q->where('is_active', $this->active === '1'))
            ->when($canViewBalance && $this->balance === 'due', $hasOutstanding)
            ->when($canViewBalance && $this->balance === 'zero', $noOutstanding)
            ->latest()
            ->paginate(15);

        // Outstanding balances for the current page only — one batched query.
        $balanceMap = $canViewBalance
            ? app(CustomerBalanceService::class)->outstandingMapForList($customers->pluck('id')->all())
            : [];

        $stats = [
            'total' => Customer::count(),
            'active' => Customer::where('is_active', true)->count(),
            'inactive' => Customer::where('is_active', false)->count(),
        ];
        if ($canViewBalance) {
            $reports = app(ReportsService::class);
            $stats['outstanding_usd'] = $reports->outstandingReceivablesUsd();
            $stats['outstanding_ils'] = $reports->receivablesIlsByDocument();
        }

        return view('livewire.admin.customers-index', [
            'customers' => $customers,
            'stats' => $stats,
            'balanceMap' => $balanceMap,
            'canViewBalance' => $canViewBalance,
            'canOpeningBalance' => $this->canManageOpeningBalance(),
            'canSendWhatsapp' => auth()->user()->can('whatsapp.send'),
        ]);
    }
}
