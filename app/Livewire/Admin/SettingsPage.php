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

    public string $invoiceTerms = '';

    public string $invoiceFooter = '';

    public string $workStart = '';

    public string $workEnd = '';

    public int $graceMinutes = 0;

    /** @var list<string> Selected working-day keys (sat..fri). */
    public array $workingDays = [];

    /** The 7 week days in RTL order with their Arabic labels. */
    public const WEEK_DAYS = [
        'sat' => 'السبت',
        'sun' => 'الأحد',
        'mon' => 'الاثنين',
        'tue' => 'الثلاثاء',
        'wed' => 'الأربعاء',
        'thu' => 'الخميس',
        'fri' => 'الجمعة',
    ];

    public function mount(Settings $settings): void
    {
        $this->authorize('settings.view');

        $this->companyName = (string) $settings->get('company', 'name', '');
        $this->companyPhone = (string) $settings->get('company', 'phone', '');
        $this->companyWhatsapp = (string) $settings->get('company', 'whatsapp', '');
        $this->companyAddress = (string) $settings->get('company', 'address', '');
        $this->taxNumber = (string) $settings->get('company', 'tax_number', '');
        $this->invoiceTerms = (string) $settings->get('finance', 'invoice_terms', '');
        $this->invoiceFooter = (string) $settings->get('finance', 'invoice_footer', '');
        $this->workStart = (string) $settings->get('attendance', 'work_start', '09:00');
        $this->workEnd = (string) $settings->get('attendance', 'work_end', '17:00');
        $this->graceMinutes = (int) $settings->get('attendance', 'grace_minutes', 15);
        // Company default working days: Saturday–Thursday.
        $this->workingDays = (array) $settings->get('attendance', 'work_days', ['sat', 'sun', 'mon', 'tue', 'wed', 'thu']);
    }

    /** Days NOT selected as working days — the weekly day(s) off, in RTL order. */
    public function weeklyOffLabels(): string
    {
        $off = array_filter(array_keys(self::WEEK_DAYS), fn ($d) => ! in_array($d, $this->workingDays, true));

        return $off === []
            ? 'لا يوجد'
            : implode('، ', array_map(fn ($d) => self::WEEK_DAYS[$d], $off));
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
            'invoiceTerms' => 'nullable|string|max:1000',
            'invoiceFooter' => 'nullable|string|max:1000',
            'workStart' => 'required|string|max:5',
            'workEnd' => 'required|string|max:5',
            'graceMinutes' => 'required|integer|min:0|max:240',
            'workingDays' => 'required|array|min:1',
            'workingDays.*' => 'in:sat,sun,mon,tue,wed,thu,fri',
        ], [
            'workingDays.required' => 'يجب اختيار يوم عمل واحد على الأقل.',
            'workingDays.min' => 'يجب اختيار يوم عمل واحد على الأقل.',
        ]);

        $settings->setMany('company', [
            'name' => $data['companyName'],
            'phone' => $data['companyPhone'] ?? '',
            'whatsapp' => $data['companyWhatsapp'] ?? '',
            'address' => $data['companyAddress'] ?? '',
            'tax_number' => $data['taxNumber'] ?? '',
        ]);
        $settings->setMany('finance', [
            'invoice_terms' => $data['invoiceTerms'] ?? '',
            'invoice_footer' => $data['invoiceFooter'] ?? '',
        ]);
        // Store working days in canonical week order; derive the weekly-off set.
        $workDays = array_values(array_filter(array_keys(self::WEEK_DAYS), fn ($d) => in_array($d, $data['workingDays'], true)));
        $weekend = array_values(array_filter(array_keys(self::WEEK_DAYS), fn ($d) => ! in_array($d, $workDays, true)));

        $settings->setMany('attendance', [
            'work_start' => $data['workStart'],
            'work_end' => $data['workEnd'],
            'grace_minutes' => ['value' => $data['graceMinutes'], 'type' => 'int'],
            'work_days' => ['value' => $workDays, 'type' => 'json'],
            'weekend' => ['value' => $weekend, 'type' => 'json'],
        ]);

        $audit->log('settings_updated', null, 'Settings', description: 'تحديث إعدادات النظام');

        session()->flash('status', 'تم حفظ الإعدادات بنجاح.');
    }

    public function render()
    {
        return view('livewire.admin.settings-page');
    }
}
