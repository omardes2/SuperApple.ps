<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Invoice;
use App\Services\CustomerBalanceService;
use App\Services\CustomerOpeningBalanceService;
use App\Services\CustomerStatementService;
use App\Services\PaymentReminderService;
use App\Support\Money;
use App\Support\TemplateRenderer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('ملف العميل')]
class CustomerProfile extends Component
{
    use WithFileUploads;

    public Customer $customer;

    public string $tab = 'overview';

    public string $attachTitle = '';

    public $attachFile = null;

    // Payment reminder modal
    public bool $showReminder = false;

    public string $reminderBody = '';

    // Opening balance modal
    public bool $showOpeningBalance = false;

    // When true the modal edits the existing posted balance (reverse + re-post)
    // instead of creating a first one.
    public bool $obEditing = false;

    public string $obType = CustomerOpeningBalance::TYPE_DEBIT;

    public string $obAmountUsd = '';

    public string $obRate = '';

    public string $obDate = '';

    public string $obNotes = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('customers.view');
        $this->customer = $customer;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function getObIlsPreviewProperty(): string
    {
        $rate = (float) ($this->obRate ?: 0);

        return $rate > 0 && $this->obAmountUsd !== '' ? Money::convertUsdToIls($this->obAmountUsd ?: 0, $this->obRate) : '0.00';
    }

    public function openOpeningBalance(): void
    {
        $this->authorize('customers.opening_balance.manage');
        $this->reset(['obType', 'obAmountUsd', 'obRate', 'obNotes']);
        $this->obEditing = false;
        $this->obDate = now()->toDateString();
        $this->resetErrorBag();
        $this->showOpeningBalance = true;
    }

    /**
     * Open the same modal pre-filled with the current posted balance so the
     * operator can correct it. Saving reverses the old document and posts the
     * corrected one — there is no in-place edit of a posted accounting entry.
     */
    public function openEditOpeningBalance(): void
    {
        $this->authorize('customers.opening_balance.manage');
        $ob = $this->customer->openingBalances()->posted()->latest('id')->first();
        if (! $ob) {
            return;
        }
        $this->obType = $ob->type;
        $this->obAmountUsd = (string) $ob->amount_usd;
        $this->obRate = (string) $ob->exchange_rate;
        $this->obDate = $ob->balance_date->toDateString();
        $this->obNotes = (string) ($ob->notes ?? '');
        $this->obEditing = true;
        $this->resetErrorBag();
        $this->showOpeningBalance = true;
    }

    public function saveOpeningBalance(CustomerOpeningBalanceService $service): void
    {
        $this->authorize('customers.opening_balance.manage');
        $this->validate([
            'obType' => 'required|in:debit,credit',
            'obAmountUsd' => 'required|numeric|gt:0',
            'obRate' => 'required|numeric|gt:0',
            'obDate' => 'required|date',
            'obNotes' => 'nullable|string|max:2000',
        ], [], ['obAmountUsd' => 'مبلغ الرصيد', 'obRate' => 'سعر الصرف', 'obDate' => 'تاريخ الرصيد']);

        $data = [
            'type' => $this->obType,
            'amount_usd' => $this->obAmountUsd,
            'exchange_rate' => $this->obRate,
            'balance_date' => $this->obDate,
            'notes' => $this->obNotes ?: null,
        ];

        try {
            if ($this->obEditing) {
                $current = $this->customer->openingBalances()->posted()->latest('id')->first();
                if (! $current) {
                    $this->addError('obAmountUsd', 'لا يوجد رصيد افتتاحي مُرحّل لتعديله.');

                    return;
                }
                $service->replace($current, $data, Auth::user(), 'تعديل الرصيد الافتتاحي');
            } else {
                $service->create($this->customer, $data);
            }
        } catch (\RuntimeException $e) {
            $this->addError('obAmountUsd', $e->getMessage());

            return;
        }

        $wasEditing = $this->obEditing;
        $this->showOpeningBalance = false;
        $this->obEditing = false;
        session()->flash('status', $wasEditing
            ? 'تم تعديل الرصيد الافتتاحي: عُكس القيد السابق وأُنشئ قيد جديد صحيح.'
            : 'تم تسجيل الرصيد الافتتاحي وقيده محاسبياً.');
    }

    public function openReminder(PaymentReminderService $reminders): void
    {
        $this->authorize('whatsapp.send');
        $template = $reminders->defaultManualTemplate();
        // Prefill an editable body: the rendered DB template if one exists,
        // otherwise a ready-made default (company name + outstanding amount).
        try {
            $this->reminderBody = $template
                ? TemplateRenderer::render($template->body, $reminders->balanceVariables($this->customer))
                : $reminders->defaultManualBody($this->customer);
        } catch (\Throwable $e) {
            $this->reminderBody = $reminders->defaultManualBody($this->customer);
        }
        $this->showReminder = true;
    }

    public function sendReminder(PaymentReminderService $reminders): void
    {
        $this->authorize('whatsapp.send');
        $this->validate(['reminderBody' => 'required|string|min:2']);
        try {
            $reminders->sendManualReminder($this->customer, $this->reminderBody);
        } catch (\Throwable $e) {
            $this->addError('reminder', $e->getMessage());

            return;
        }
        $this->showReminder = false;
        session()->flash('status', 'تم إرسال التذكير عبر واتساب.');
    }

    public function addAttachment(): void
    {
        $this->authorize('customers.attachments');

        $this->validate([
            'attachTitle' => 'nullable|string|max:150',
            'attachFile' => 'required|file|max:10240',
        ]);

        $path = $this->attachFile->store("customer-attachments/{$this->customer->id}", 'local');

        $this->customer->attachments()->create([
            'title' => $this->attachTitle ?: $this->attachFile->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $this->attachFile->getClientOriginalName(),
            'mime' => $this->attachFile->getMimeType(),
            'size' => $this->attachFile->getSize(),
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset(['attachTitle', 'attachFile']);
        session()->flash('status', 'تم رفع المرفق.');
    }

    public function render()
    {
        $data = [];

        if ($this->tab === 'tasks') {
            $data['tasks'] = $this->customer->tasks()->with('primaryAssignee')->latest()->limit(60)->get();
        }

        if ($this->tab === 'attachments') {
            $data['attachments'] = $this->customer->attachments()->with('uploader')->get();
        }

        if ($this->tab === 'activity') {
            $data['activity'] = AuditLog::where('auditable_type', $this->customer->getMorphClass())
                ->where('auditable_id', $this->customer->id)
                ->with('user')->latest('created_at')->limit(50)->get();
        }

        // Financial tabs — only queried and shown for authorised users.
        $data['canInvoices'] = auth()->user()->can('invoices.view');
        $data['canPayments'] = auth()->user()->can('payments.view');
        $data['canStatement'] = auth()->user()->can('customer_statements.view');

        if ($this->tab === 'invoices' && $data['canInvoices']) {
            $data['invoices'] = $this->customer->hasMany(Invoice::class)->latest()->get();
        }

        // Official USD balance (3 distinct figures) — shown only to finance users.
        if ($data['canPayments']) {
            $data['balance'] = app(CustomerBalanceService::class)->summary($this->customer);
        }

        // Opening balance (finance-gated): the posted document, if any.
        $data['canOpeningBalance'] = auth()->user()->can('customers.opening_balance.manage');
        $data['openingBalance'] = $data['canOpeningBalance']
            ? $this->customer->openingBalances()->posted()->latest('id')->first()
            : null;

        if ($this->tab === 'payments' && $data['canPayments']) {
            $data['payments'] = $this->customer->payments()->with('receivedBy')->latest('payment_date')->latest('id')->get();
        }

        // Account statement (USD official) — read-only, from the statement
        // service; gated on the same permission as the full statement page.
        if ($this->tab === 'statement' && $data['canStatement']) {
            $data['statement'] = app(CustomerStatementService::class)->build($this->customer);
        }

        // WhatsApp (Sprint 7). Subscriptions module retired.
        $data['canWhatsapp'] = auth()->user()->can('whatsapp.view');
        $data['canSendWhatsapp'] = auth()->user()->can('whatsapp.send');

        if ($this->tab === 'communications' && $data['canWhatsapp']) {
            $data['messages'] = $this->customer->whatsappMessages()->with('invoice')->limit(50)->get();
        }

        return view('livewire.admin.customer-profile', $data);
    }
}
