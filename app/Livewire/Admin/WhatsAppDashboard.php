<?php

namespace App\Livewire\Admin;

use App\Enums\WhatsAppMessageStatus;
use App\Models\WhatsAppMessage;
use App\Services\Settings;
use App\Services\WhatsAppService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('واتساب')]
class WhatsAppDashboard extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    // Settings (managed by whatsapp.settings.manage)
    public bool $enabled = false;

    public string $provider = 'null';

    public string $default_country_code = '';

    public function mount(Settings $settings): void
    {
        $this->authorize('whatsapp.view');
        $this->enabled = (bool) $settings->get('whatsapp', 'enabled', false);
        $this->provider = (string) $settings->get('whatsapp', 'provider', 'null');
        $this->default_country_code = (string) $settings->get('whatsapp', 'default_country_code', '');
    }

    public function saveSettings(Settings $settings): void
    {
        $this->authorize('whatsapp.settings.manage');
        $this->validate([
            'provider' => 'required|in:null,log,fake',
            'default_country_code' => 'nullable|string|max:5',
        ]);
        $settings->set('whatsapp', 'enabled', $this->enabled, 'bool');
        $settings->set('whatsapp', 'provider', $this->provider, 'string');
        $settings->set('whatsapp', 'default_country_code', $this->default_country_code, 'string');
        session()->flash('status', 'تم حفظ إعدادات واتساب.');
    }

    public function retry(int $id, WhatsAppService $service): void
    {
        $message = WhatsAppMessage::findOrFail($id);
        $this->authorize('retry', $message);
        try {
            $service->retry($message);
        } catch (\RuntimeException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }
        session()->flash('status', 'أُعيد جدولة الرسالة للإرسال.');
    }

    public function render()
    {
        $messages = WhatsAppMessage::with(['customer', 'invoice'])
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')->paginate(20);

        $counts = WhatsAppMessage::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return view('livewire.admin.whatsapp-dashboard', [
            'messages' => $messages,
            'statuses' => WhatsAppMessageStatus::options(),
            'counts' => $counts,
            'canRetry' => auth()->user()->can('whatsapp.retry'),
            'canSettings' => auth()->user()->can('whatsapp.settings.manage'),
        ]);
    }
}
