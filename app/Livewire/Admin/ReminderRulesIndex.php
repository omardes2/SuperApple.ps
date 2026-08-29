<?php

namespace App\Livewire\Admin;

use App\Enums\ReminderTimingType;
use App\Models\PaymentReminderRule;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('قواعد التذكير')]
class ReminderRulesIndex extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public int $offset_days = 0;

    public string $timing_type = 'before_due';

    public ?int $template_id = null;

    public bool $is_active = true;

    public string $send_time = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PaymentReminderRule::class);
    }

    public function openCreate(): void
    {
        $this->authorize('create', PaymentReminderRule::class);
        $this->reset(['editingId', 'name', 'offset_days', 'timing_type', 'template_id', 'send_time']);
        $this->timing_type = 'before_due';
        $this->is_active = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $r = PaymentReminderRule::findOrFail($id);
        $this->authorize('update', $r);
        $this->editingId = $r->id;
        $this->name = $r->name;
        $this->offset_days = (int) $r->offset_days;
        $this->timing_type = $r->timing_type->value;
        $this->template_id = $r->template_id;
        $this->is_active = $r->is_active;
        $this->send_time = (string) $r->send_time;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:150',
            'offset_days' => 'required|integer|min:0',
            'timing_type' => 'required|in:before_due,due_date,after_due',
            'template_id' => 'required|integer|exists:whatsapp_templates,id',
        ]);

        $data = [
            'name' => $this->name,
            'offset_days' => $this->offset_days,
            'timing_type' => $this->timing_type,
            'template_id' => $this->template_id,
            'is_active' => $this->is_active,
            'send_time' => $this->send_time ?: null,
            'updated_by' => Auth::id(),
        ];

        if ($this->editingId) {
            $r = PaymentReminderRule::findOrFail($this->editingId);
            $this->authorize('update', $r);
            $r->update($data);
        } else {
            $this->authorize('create', PaymentReminderRule::class);
            PaymentReminderRule::create(array_merge($data, ['created_by' => Auth::id()]));
        }

        $this->showForm = false;
        session()->flash('status', 'تم حفظ قاعدة التذكير.');
    }

    public function render()
    {
        return view('livewire.admin.reminder-rules-index', [
            'rules' => PaymentReminderRule::with('template')->orderBy('timing_type')->orderBy('offset_days')->get(),
            'timings' => ReminderTimingType::options(),
            'templates' => WhatsAppTemplate::active()->orderBy('name')->get(),
            'canManage' => auth()->user()->can('whatsapp.reminders.manage'),
        ]);
    }
}
