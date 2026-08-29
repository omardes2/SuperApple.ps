<?php

namespace App\Livewire\Admin;

use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use App\Support\TemplateRenderer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('قوالب واتساب')]
class WhatsAppTemplatesIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $key = '';

    public string $category = 'manual_message';

    public string $body = '';

    public bool $is_active = true;

    public string $preview = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WhatsAppTemplate::class);
    }

    public function openCreate(): void
    {
        $this->authorize('create', WhatsAppTemplate::class);
        $this->reset(['editingId', 'name', 'key', 'category', 'body', 'preview']);
        $this->is_active = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $t = WhatsAppTemplate::findOrFail($id);
        $this->authorize('update', $t);
        $this->editingId = $t->id;
        $this->name = $t->name;
        $this->key = $t->key;
        $this->category = $t->category;
        $this->body = $t->body;
        $this->is_active = $t->is_active;
        $this->preview = '';
        $this->showForm = true;
    }

    public function renderPreview(): void
    {
        try {
            $this->preview = TemplateRenderer::render($this->body, $this->sampleVariables());
        } catch (\Throwable $e) {
            $this->preview = '⚠ '.$e->getMessage();
        }
    }

    public function save(WhatsAppTemplateService $service): void
    {
        $this->validate([
            'name' => 'required|string|max:150',
            'body' => 'required|string',
            'key' => 'required|string|max:100',
        ]);

        if ($this->editingId) {
            $t = WhatsAppTemplate::findOrFail($this->editingId);
            $this->authorize('update', $t);
            $service->update($t, ['name' => $this->name, 'category' => $this->category, 'body' => $this->body, 'is_active' => $this->is_active]);
        } else {
            $this->authorize('create', WhatsAppTemplate::class);
            $service->create(['name' => $this->name, 'key' => $this->key, 'category' => $this->category, 'body' => $this->body, 'is_active' => $this->is_active]);
        }

        $this->showForm = false;
        session()->flash('status', 'تم حفظ القالب.');
    }

    /** @return array<string,string> */
    private function sampleVariables(): array
    {
        return [
            'customer_name' => 'شركة نموذج', 'invoice_number' => 'INV-2026-0001',
            'invoice_total_usd' => '600.00', 'invoice_remaining_usd' => '600.00',
            'due_date' => now()->addDays(30)->toDateString(), 'subscription_name' => 'باقة شهرية',
            'balance_usd' => '600.00', 'balance_ils' => '2190.00', 'invoice_list' => '• INV-2026-0001 — 600.00 USD',
            'payment_amount' => '500.00', 'payment_currency' => 'ILS',
        ];
    }

    public function render()
    {
        return view('livewire.admin.whatsapp-templates-index', [
            'templates' => WhatsAppTemplate::orderBy('category')->orderBy('name')->get(),
            'canManage' => auth()->user()->can('whatsapp.templates.manage'),
        ]);
    }
}
