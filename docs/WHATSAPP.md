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

## إعداد واتساب للإنتاج (WhatsApp production configuration)

التطبيق يُشحن افتراضياً بمزوّد **Null** آمن، والإعداد `whatsapp.enabled = false`، أي
**لا تُرسَل أي رسائل حقيقية** حتى يفعّلها مشغّل النظام قصداً. لإرسال رسائل فعلية في
الإنتاج نفّذ الخطوات التالية:

### 1) اختر مزوّداً حقيقياً

- **Meta WhatsApp Cloud API** (رسمي من Meta)، أو
- **360dialog** (شريك BSP).

البنية جاهزة لكليهما عبر عقد `App\Contracts\WhatsAppProvider` دون إعادة تصميم؛ يُختار
المزوّد وقت التشغيل من الإعداد `whatsapp.provider` (`null` | `log` | `fake` |
لاحقاً `meta_cloud` | `dialog360`).

### 2) مكان بيانات الاعتماد

- **الإعدادات التشغيلية** (جدول `settings` عبر شاشة الإعدادات): `whatsapp.enabled`،
  `whatsapp.provider`، `whatsapp.default_country_code` (الافتراضي `970`).
- **توكنات/مفاتيح المزوّد** توضَع في **متغيّرات البيئة أو إعدادات آمنة على الخادم فقط**،
  **لا في الشيفرة ولا في المستودع** (عناصر نائبة — placeholders):

  ```dotenv
  WHATSAPP_PHONE_NUMBER_ID=<your-phone-number-id>
  WHATSAPP_TOKEN=<your-permanent-access-token>
  # 360dialog بدلاً من ذلك:
  # WHATSAPP_360_API_KEY=<your-360dialog-api-key>
  ```

> لا تضع أي بيانات اعتماد حقيقية في هذا الملف أو في أي ملف يُلتزم به في المستودع.

### 3) التفعيل

اضبط `whatsapp.provider` على المزوّد المختار و`whatsapp.enabled = true` بعد إدخال
بيانات الاعتماد. أرسل رسالة اختبار من شاشة واتساب للتحقق قبل الاعتماد على الأتمتة.

### 4) اعتماد القوالب مسبقاً (Cloud API)

Meta Cloud API تشترط **اعتماد القوالب مسبقاً** قبل الإرسال. يجب أن تُطابق أسماء ولغات
القوالب المعتمدة لدى Meta مفاتيح القوالب في التطبيق (`invoice_issued`,
`payment_reminder_*`, `payment_received`, `subscription_invoice_created`, ...). أي رسالة
خارج نافذة الـ 24 ساعة يجب أن تُرسَل عبر قالب معتمد.

### 5) الـ Webhooks (تحسين مستقبلي)

استقبال **إيصالات التسليم/القراءة** (delivery/read receipts) والرسائل **الواردة**
(inbound) عبر webhooks **غير مُنفَّذ بعد** — تحسين مستقبلي. حالياً تُحدَّث حالة الرسالة
عبر الإرسال وإعادة المحاولة فقط، ولا يوجد استقبال رسائل واردة.

