# الفواتير المتكررة (Recurring Invoices)

**مبدأ أساسي:** الفاتورة المتكررة ليست نوعاً محاسبياً جديداً. هي فاتورة عادية تماماً
تُنشأ عبر `InvoiceService` وتخضع لكل القواعد القائمة:

- العملة USD، لقطة سعر الصرف عند الإصدار، لقطة العميل، لقطة البنود.
- ترحيل القيد المحاسبي (GL) عند الإصدار، وعدم قابلية التعديل بعد الإصدار.
- الدفعات، رصيد العميل، والمحاسبة — كلها كما هي.

الرابط الوحيد الإضافي هو العمود `invoices.subscription_id` (معلوماتي فقط).

## المحرّك: `SubscriptionBillingService`

`billOne(subscriptionId, onDate, dryRun=false)` و`runDue(onDate, dryRun, onlyId)`.

خطوات فوترة اشتراك واحد (داخل معاملة قاعدة بيانات مستقلة):

1. قفل صف الاشتراك (`lockForUpdate`) والتأكد أنه **نشط** وله `next_billing_date`
   مستحق (`<= onDate`).
2. حساب الفترة: `period_start = next_billing_date`، `period_end = advance(start) − يوم`.
3. **منع التكرار**: التحقق من عدم وجود `subscription_billings` لنفس (الاشتراك،
   period_start، period_end). الضمان النهائي هو الفهرس الفريد
   `unique(subscription_id, period_start, period_end)`.
4. إنشاء **مسودة** فاتورة عبر `InvoiceService::createDraft` مع نسخ البنود والعميل
   والمشروع والشروط والملاحظات، وضبط تاريخ الفاتورة والاستحقاق
   (`payment_terms_days` أو `default_invoice_due_days`)، وربط `subscription_id`.
5. تسجيل صف `subscription_billings` (حالة `generated`).
6. **تحديث `next_billing_date`** إلى الفترة التالية — **فقط بعد نجاح التوليد**.
   إن تجاوزت الفترة التالية `end_date` يصبح الاشتراك «منتهٍ».
7. **الإصدار التلقائي** (إن كان `auto_issue_invoice`): جلب أحدث سعر صرف
   (`rate_date <= invoice_date`). فإن **لم يوجد سعر**: تُترك الفاتورة مسودة، ويُسجَّل
   خطأ على صف الفوترة، ويُشعَر المحاسب (`SubscriptionAutoIssueFailed`) — **لا تخمين
   لسعر الصرف، ولا إعادة إنشاء مسودة لنفس الفترة** (صف الفوترة يمنع ذلك). فإن وُجد
   سعر: تُثبَّت الفاتورة وتُصدر عبر `InvoiceService::issue` (يرحّل GL).

`auto_generate_invoice` و`auto_issue_invoice` مستقلّان: يمكن توليد مسودة دون إصدار.

## التزامن ومنع التكرار

ثلاث طبقات: قفل صف الاشتراك + فحص وجود صف الفوترة + الفهرس الفريد. عند سباق متزامن
يُلتقط انتهاك القيد الفريد ويُعاد `skipped`. كل اشتراك في معاملته الخاصة، فشل أحدها لا
يُفشل الباقي.

## الأمر والمجدول

```
php artisan subscriptions:bill                 # فوترة المستحق اليوم
php artisan subscriptions:bill --date=2026-09-01
php artisan subscriptions:bill --dry-run       # لا يكتب شيئاً، تقرير فقط
php artisan subscriptions:bill --subscription=12
```

مُسجَّل في `routes/console.php` يومياً (02:00) مع `withoutOverlapping`.
وضع `--dry-run` لا يكتب أي شيء ولا يُقدّم `next_billing_date`.

## إشعار واتساب

عند إصدار فاتورة اشتراك تلقائياً، يُرسَل إشعار واتساب **بعد** تثبيت المعاملة المالية
(Job على الطابور)؛ فشل واتساب لا يؤثر إطلاقاً على الفاتورة.
