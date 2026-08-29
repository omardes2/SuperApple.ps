<?php

namespace Database\Seeders;

use App\Enums\ReminderTimingType;
use App\Models\PaymentReminderRule;
use App\Models\WhatsAppTemplate;
use App\Support\TemplateRenderer;
use Illuminate\Database\Seeder;

/**
 * Seeds the default Arabic WhatsApp templates and payment reminder rules. No
 * real messages are ever sent by seeding — the WhatsApp channel ships disabled
 * and the default provider is the Null driver.
 */
class WhatsAppSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            WhatsAppTemplate::updateOrCreate(
                ['key' => $tpl['key']],
                array_merge($tpl, ['variables_schema' => TemplateRenderer::referencedVariables($tpl['body'])]),
            );
        }

        $this->reminderRules();
    }

    /** @return list<array<string,mixed>> */
    private function templates(): array
    {
        return [
            [
                'key' => WhatsAppTemplate::KEY_INVOICE_ISSUED,
                'name' => 'إشعار إصدار فاتورة',
                'category' => 'invoice',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nتم إصدار الفاتورة رقم {{invoice_number}} بقيمة {{invoice_total_usd}} دولار.\nتاريخ الاستحقاق: {{due_date}}.\nشكراً لتعاملكم معنا.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_SUBSCRIPTION_INVOICE,
                'name' => 'إشعار فاتورة اشتراك',
                'category' => 'subscription',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nتم إصدار فاتورة اشتراك {{subscription_name}} رقم {{invoice_number}} بقيمة {{invoice_total_usd}} دولار.\nتاريخ الاستحقاق: {{due_date}}.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_REMINDER_BEFORE_DUE,
                'name' => 'تذكير قبل الاستحقاق',
                'category' => 'reminder',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nنذكّركم بأن الفاتورة {{invoice_number}} بقيمة متبقية {{invoice_remaining_usd}} دولار تستحق بتاريخ {{due_date}}.\nنشكر التزامكم.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_REMINDER_DUE_TODAY,
                'name' => 'تذكير يوم الاستحقاق',
                'category' => 'reminder',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nتستحق اليوم الفاتورة {{invoice_number}} بقيمة متبقية {{invoice_remaining_usd}} دولار.\nنرجو إتمام الدفع.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_REMINDER_OVERDUE,
                'name' => 'تذكير بعد الاستحقاق',
                'category' => 'reminder',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nالفاتورة {{invoice_number}} بقيمة متبقية {{invoice_remaining_usd}} دولار تجاوزت تاريخ الاستحقاق {{due_date}}.\nنرجو المبادرة بالسداد.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_REMINDER_MANUAL,
                'name' => 'تذكير يدوي بالرصيد',
                'category' => 'reminder',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nرصيدكم المستحق لدينا {{balance_usd}} دولار (ما يعادل تقريباً {{balance_ils}} شيكل حسب آخر سعر صرف).\nالفواتير المستحقة:\n{{invoice_list}}\nنشكر لكم التعاون.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_PAYMENT_RECEIVED,
                'name' => 'إشعار استلام دفعة',
                'category' => 'payment',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\nاستلمنا دفعتكم بقيمة {{payment_amount}} {{payment_currency}}.\nرصيدكم الحالي: {{balance_usd}} دولار.\nشكراً لكم.",
            ],
            [
                'key' => WhatsAppTemplate::KEY_MANUAL,
                'name' => 'رسالة يدوية',
                'category' => 'manual_message',
                'language' => 'ar',
                'is_active' => true,
                'body' => "مرحباً {{customer_name}}،\n",
            ],
        ];
    }

    private function reminderRules(): void
    {
        $before = WhatsAppTemplate::where('key', WhatsAppTemplate::KEY_REMINDER_BEFORE_DUE)->first();
        $onDue = WhatsAppTemplate::where('key', WhatsAppTemplate::KEY_REMINDER_DUE_TODAY)->first();
        $after = WhatsAppTemplate::where('key', WhatsAppTemplate::KEY_REMINDER_OVERDUE)->first();

        $rules = [
            ['name' => 'تذكير قبل الاستحقاق بـ 3 أيام', 'offset_days' => 3, 'timing_type' => ReminderTimingType::BeforeDue->value, 'template_id' => $before?->id],
            ['name' => 'تذكير يوم الاستحقاق', 'offset_days' => 0, 'timing_type' => ReminderTimingType::DueDate->value, 'template_id' => $onDue?->id],
            ['name' => 'تذكير بعد الاستحقاق بـ 7 أيام', 'offset_days' => 7, 'timing_type' => ReminderTimingType::AfterDue->value, 'template_id' => $after?->id],
        ];

        foreach ($rules as $rule) {
            PaymentReminderRule::updateOrCreate(
                ['name' => $rule['name']],
                array_merge($rule, ['is_active' => true]),
            );
        }
    }
}
