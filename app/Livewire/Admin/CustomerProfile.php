<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
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

    public function mount(Customer $customer): void
    {
        $this->authorize('customers.view');
        $this->customer = $customer;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
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

        if ($this->tab === 'projects') {
            $data['projects'] = $this->customer->projects()->withCount('tasks')->latest()->get();
        }

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
        $data['canQuotations'] = auth()->user()->can('quotations.view');
        $data['canInvoices'] = auth()->user()->can('invoices.view');

        if ($this->tab === 'quotations' && $data['canQuotations']) {
            $data['quotations'] = $this->customer->hasMany(Quotation::class)->latest()->get();
        }

        if ($this->tab === 'invoices' && $data['canInvoices']) {
            $data['invoices'] = $this->customer->hasMany(Invoice::class)->latest()->get();
        }

        return view('livewire.admin.customer-profile', $data);
    }
}
