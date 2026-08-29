# واتساب (WhatsApp)

قناة مراسلة صادرة للعملاء: إشعارات الفواتير، فواتير الاشتراكات، إشعارات استلام
الدفعات، تذكيرات الدفع (انظر `PAYMENT_REMINDERS.md`)، والرسائل اليدوية.

## مبدأ العزل المالي

**فشل واتساب لا يُفشل أبداً إصدار فاتورة أو ترحيل دفعة.** يتحقق ذلك بأن:

- الشيفرة المالية تُنهي معاملتها وتُثبّتها أولاً، ثم تستدعي دوال `notify*`.
- كل رسالة تُحفظ صفاً في `whatsapp_messages` وتُسلَّم إلى `SendWhatsAppMessageJob` على
  الطابور.
- الوظيفة تعيد المحاولة حتى 3 مرات بتراجع زمني (30s/120s/300s)، وعند الفشل النهائي
  تُعلّم الرسالة `Failed` وتُشعر الفريق — **بلا إعادة محاولة لا نهائية**.

## تجريد المزوّد

عقد `App\Contracts\WhatsAppProvider`: `sendText`, `sendTemplate`, `getMessageStatus`,
`key`. التطبيقات:

- `NullWhatsAppProvider` — الافتراضي الآمن: لا شبكة، يتظاهر بالإرسال.
- `LogWhatsAppProvider` — يكتب إلى سجل التطبيق.
- `FakeWhatsAppProvider` — للاختبار دون إنترنت (يسجّل الرسائل ويمكن إجباره على الفشل).

يُختار المزوّد وقت التشغيل من الإعداد `whatsapp.provider` (انظر
`AppServiceProvider`). البنية جاهزة لإضافة Meta WhatsApp Cloud API / 360dialog دون
إعادة تصميم.

> **لا تُخزَّن أي بيانات اعتماد في الشيفرة.** كل التوكنات/المفاتيح تُقرأ من
> الإعدادات/البيئة فقط، ولا يُلتزم بها في المستودع.

## القوالب

جدول `whatsapp_templates`: `name`, `key`, `category`, `language`, `body`,
`is_active`, `variables_schema?`. المفاتيح الجاهزة: `invoice_issued`,
`payment_reminder_before_due/due_today/overdue`, `payment_reminder_manual`,
`payment_received`, `subscription_invoice_created`, `manual_message`.

**العرض صارم** (`TemplateRenderer`): إذا أشار القالب إلى متغيّر غير مُتاح (أو قيمته
`null`) يُرفض العرض ويُسجَّل الخطأ — لا تُرسل رسالة ناقصة أبداً. المتغيّرات المدعومة:
`customer_name, invoice_number, invoice_total_usd, invoice_remaining_usd, due_date,
balance_usd, balance_ils, payment_amount, payment_currency, subscription_name,
invoice_list`. تتوفر معاينة قبل الإرسال اليدوي.

## الرسائل

جدول `whatsapp_messages`: روابط اختيارية (`customer/invoice/payment/subscription/
template`)، `phone`, `message_body`, `provider`, `provider_message_id?`, `direction`,
`status`, وطوابع الوقت (`scheduled_for/sent_at/delivered_at/read_at/failed_at`) و
`failure_reason?`. الحالات: Pending/Queued/Sent/Delivered/Read/Failed/Cancelled.

## تطبيع الأرقام

`PhoneNormalizer` يفضّل `whatsapp_number` على `phone`، ويطبّع إلى صيغة `+<أرقام>`،
ويرفض غير الصالح (يعيد `null`). **رمز الدولة الافتراضي** يُقرأ من الإعداد
`whatsapp.default_country_code` (لا قيمة مضمّنة في الشيفرة).

## الصلاحيات

`whatsapp.view/send/retry/templates.view/templates.manage/settings.manage/
reminders.view/reminders.manage/history.view`. المدير العام: الكل. المحاسب: الإرسال،
التذكيرات، السجل، عرض القوالب (بلا إدارة القوالب/الإعدادات).
