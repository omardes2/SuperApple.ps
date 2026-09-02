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

    // Meta WhatsApp Cloud API credentials.
    public string $metaPhoneNumberId = '';

    public string $metaApiVersion = 'v21.0';

    // Write-only: left blank on load; a blank value keeps the stored token.
    public string $metaAccessToken = '';

    public bool $metaTokenSet = false;

    public function mount(Settings $settings): void
    {
        $this->authorize('whatsapp.view');
        $this->enabled = (bool) $settings->get('whatsapp', 'enabled', false);
        $this->provider = (string) $settings->get('whatsapp', 'provider', 'null');
        $this->default_country_code = (string) $settings->get('whatsapp', 'default_country_code', '');
        $this->metaPhoneNumberId = (string) $settings->get('whatsapp', 'meta_phone_number_id', '');
        $this->metaApiVersion = (string) ($settings->get('whatsapp', 'meta_api_version') ?: 'v21.0');
        $this->metaTokenSet = filled($settings->get('whatsapp', 'meta_access_token'));
    }

    public function saveSettings(Settings $settings): void
    {
        $this->authorize('whatsapp.settings.manage');
        $this->validate([
            'provider' => 'required|in:null,log,fake,meta_cloud',
            'default_country_code' => 'nullable|string|max:5',
            'metaPhoneNumberId' => 'nullable|string|max:40',
            'metaApiVersion' => 'nullable|string|max:10',
            'metaAccessToken' => 'nullable|string|max:2000',
        ]);

        // Enabling the Cloud API needs a phone number id and a token (stored or
        // just entered) — otherwise sends would silently fail.
        if ($this->provider === 'meta_cloud' && $this->enabled) {
            if ($this->metaPhoneNumberId === '') {
                $this->addError('metaPhoneNumberId', 'رقم الهاتف (Phone Number ID) مطلوب لتفعيل واتساب.');

                return;
            }
            if (! $this->metaTokenSet && $this->metaAccessToken === '') {
                $this->addError('metaAccessToken', 'رمز الوصول (Access Token) مطلوب لتفعيل واتساب.');

                return;
            }
        }

        $settings->set('whatsapp', 'enabled', $this->enabled, 'bool');
        $settings->set('whatsapp', 'provider', $this->provider, 'string');
        $settings->set('whatsapp', 'default_country_code', $this->default_country_code, 'string');
        $settings->set('whatsapp', 'meta_phone_number_id', $this->metaPhoneNumberId, 'string');
        $settings->set('whatsapp', 'meta_api_version', $this->metaApiVersion ?: 'v21.0', 'string');

        // Only overwrite the token when a new one is typed; a blank field keeps
        // the existing secret so it never needs re-entering.
        if ($this->metaAccessToken !== '') {
            $settings->set('whatsapp', 'meta_access_token', $this->metaAccessToken, 'string');
            $this->metaAccessToken = '';
            $this->metaTokenSet = true;
        }

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
