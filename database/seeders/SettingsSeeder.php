<?php

namespace Database\Seeders;

use App\Services\Settings;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);

        $settings->setMany('company', [
            'name' => ['value' => 'SuperApple Creative Agency', 'type' => 'string'],
            'phone' => ['value' => '', 'type' => 'string'],
            'whatsapp' => ['value' => '', 'type' => 'string'],
            'address' => ['value' => '', 'type' => 'string'],
            'tax_number' => ['value' => '', 'type' => 'string'],
            'logo' => ['value' => '', 'type' => 'string'],
        ]);

        // Fixed currency business rules.
        $settings->setMany('finance', [
            'default_currency' => ['value' => 'ILS', 'type' => 'string'],       // accounting base
            'base_accounting_currency' => ['value' => 'ILS', 'type' => 'string'],
            'invoice_currency' => ['value' => 'USD', 'type' => 'string'],       // customer invoicing
            'default_invoice_currency' => ['value' => 'USD', 'type' => 'string'],
            'default_exchange_rate' => ['value' => '3.30', 'type' => 'decimal'],
            'default_invoice_due_days' => ['value' => '30', 'type' => 'int'],
            'quotation_validity_days' => ['value' => '14', 'type' => 'int'],
            'invoice_terms' => ['value' => 'الدفع خلال 30 يوماً من تاريخ الفاتورة.', 'type' => 'string'],
            'invoice_footer' => ['value' => 'القيمة الأساسية المستحقة لهذه الفاتورة هي بالدولار الأمريكي، ويتم احتساب أي دفعة بالشيكل وفق سعر الصرف المسجل وقت استلام الدفعة.', 'type' => 'string'],
            'quotation_terms' => ['value' => 'هذا العرض ساري حتى تاريخ الصلاحية المذكور. الأسعار بالدولار الأمريكي.', 'type' => 'string'],
        ]);

        $settings->setMany('attendance', [
            'work_start' => ['value' => '09:00', 'type' => 'string'],
            'work_end' => ['value' => '17:00', 'type' => 'string'],
            'grace_minutes' => ['value' => '15', 'type' => 'int'],
            'work_days' => ['value' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'type' => 'json'],
            'weekend' => ['value' => ['fri', 'sat'], 'type' => 'json'],
            'default_working_hours' => ['value' => '8', 'type' => 'int'],
        ]);

        $settings->setMany('payroll', [
            'overtime_rate' => ['value' => '1.5', 'type' => 'decimal'],
        ]);

        $settings->setMany('whatsapp', [
            'enabled' => ['value' => false, 'type' => 'bool'],
            'provider' => ['value' => 'manual', 'type' => 'string'],
        ]);
    }
}
