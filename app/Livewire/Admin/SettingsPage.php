<?php

namespace App\Livewire\Admin;

use App\Services\AuditLogger;
use App\Services\Settings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الإعدادات')]
class SettingsPage extends Component
{
    public string $companyName = '';

    public string $companyPhone = '';

    public string $companyWhatsapp = '';

    public string $companyAddress = '';

    public string $taxNumber = '';

    public string $defaultExchangeRate = '';

    public string $invoiceTerms = '';

    public string $invoiceFooter = '';

    public string $workStart = '';

    public string $workEnd = '';

    public int $graceMinutes = 0;

    public function mount(Settings $settings): void
    {
        $this->authorize('settings.view');

        $this->companyName = (string) $settings->get('company', 'name', '');
        $this->companyPhone = (string) $settings->get('company', 'phone', '');
        $this->companyWhatsapp = (string) $settings->get('company', 'whatsapp', '');
        $this->companyAddress = (string) $settings->get('company', 'address', '');
        $this->taxNumber = (string) $settings->get('company', 'tax_number', '');
        $this->defaultExchangeRate = (string) $settings->get('finance', 'default_exchange_rate', '3.30');
        $this->invoiceTerms = (string) $settings->get('finance', 'invoice_terms', '');
        $this->invoiceFooter = (string) $settings->get('finance', 'invoice_footer', '');
        $this->workStart = (string) $settings->get('attendance', 'work_start', '09:00');
        $this->workEnd = (string) $settings->get('attendance', 'work_end', '17:00');
        $this->graceMinutes = (int) $settings->get('attendance', 'grace_minutes', 15);
    }

    public function save(Settings $settings, AuditLogger $audit): void
    {
        $this->authorize('settings.manage');

        $data = $this->validate([
            'companyName' => 'required|string|max:150',
            'companyPhone' => 'nullable|string|max:40',
            'companyWhatsapp' => 'nullable|string|max:40',
            'companyAddress' => 'nullable|string|max:255',
            'taxNumber' => 'nullable|string|max:60',
            'defaultExchangeRate' => 'required|numeric|min:0.000001',
            'invoiceTerms' => 'nullable|string|max:1000',
            'invoiceFooter' => 'nullable|string|max:1000',
            'workStart' => 'required|string|max:5',
            'workEnd' => 'required|string|max:5',
            'graceMinutes' => 'required|integer|min:0|max:240',
        ]);

        $settings->setMany('company', [
            'name' => $data['companyName'],
            'phone' => $data['companyPhone'] ?? '',
            'whatsapp' => $data['companyWhatsapp'] ?? '',
            'address' => $data['companyAddress'] ?? '',
            'tax_number' => $data['taxNumber'] ?? '',
        ]);
        $settings->setMany('finance', [
            'default_exchange_rate' => ['value' => $data['defaultExchangeRate'], 'type' => 'decimal'],
            'invoice_terms' => $data['invoiceTerms'] ?? '',
            'invoice_footer' => $data['invoiceFooter'] ?? '',
        ]);
        $settings->setMany('attendance', [
            'work_start' => $data['workStart'],
            'work_end' => $data['workEnd'],
            'grace_minutes' => ['value' => $data['graceMinutes'], 'type' => 'int'],
        ]);

        $audit->log('settings_updated', null, 'Settings', description: 'تحديث إعدادات النظام');

        session()->flash('status', 'تم حفظ الإعدادات بنجاح.');
    }

    public function render()
    {
        return view('livewire.admin.settings-page');
    }
}
