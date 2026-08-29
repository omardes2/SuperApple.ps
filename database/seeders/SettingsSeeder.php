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
            'default_currency' => ['value' => 'ILS', 'type' => 'string'],  // accounting base
            'invoice_currency' => ['value' => 'USD', 'type' => 'string'],  // customer invoicing
            'default_exchange_rate' => ['value' => '3.30', 'type' => 'decimal'],
            'invoice_terms' => ['value' => 'الدفع خلال 15 يوماً من تاريخ الفاتورة.', 'type' => 'string'],
            'invoice_footer' => ['value' => 'شكراً لتعاملكم معنا.', 'type' => 'string'],
        ]);

        $settings->setMany('attendance', [
            'work_start' => ['value' => '09:00', 'type' => 'string'],
            'work_end' => ['value' => '17:00', 'type' => 'string'],
            'grace_minutes' => ['value' => '15', 'type' => 'int'],
            'work_days' => ['value' => ['sun', 'mon', 'tue', 'wed', 'thu'], 'type' => 'json'],
            'weekend' => ['value' => ['fri', 'sat'], 'type' => 'json'],
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
