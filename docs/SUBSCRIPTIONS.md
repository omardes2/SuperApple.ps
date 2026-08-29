# الاشتراكات (Subscriptions)

يدير هذا النموذج العقود المتكررة مع العملاء (Retainers/Subscriptions). الاشتراك
**لا يُنشئ أي قيود محاسبية بنفسه** — هو فقط يصف *ماذا* و*كل متى* يُفوتَر. كل فاتورة
فعلية تُنشأ عبر `InvoiceService` وتخضع لكل قواعد الفوترة القياسية (انظر
`RECURRING_INVOICES.md`).

## النموذج

جدول `subscriptions`:

- `subscription_number` — تسلسل `SUB-YYYY-####`.
- `customer_id`, `project_id?`, `name`, `description?`.
- `billing_cycle` — أسبوعي/شهري/ربع سنوي/نصف سنوي/سنوي/مخصص.
- `billing_interval` — «كل N دورة» (مثال: شهري + 2 = كل شهرين).
- `start_date`, `end_date?`, `next_billing_date`, `last_billed_at?`.
- `payment_terms_days?` — يتجاوز مهلة السداد الافتراضية.
- `currency` (USD دائماً)، `subtotal_usd/discount_usd/tax_usd/total_usd` — لقطة
  متجمّدة للأسعار وقت التعاقد.
- `auto_generate_invoice` (يُنشئ مسودة) و`auto_issue_invoice` (يُصدر بعد الإنشاء) —
  **مستقلّان تماماً**.
- `status` — مسودة/نشط/موقوف مؤقتاً/ملغى/منتهٍ.
- حقول دورة الحياة: `activated_at/paused_at/cancelled_at/cancelled_by/cancellation_reason`.

جدول `subscription_items`: `service_id?`, `item_name`, `description?`, `quantity`,
`unit_price_usd`, `discount_type?`, `discount_value?`, `tax_rate`, `sort_order`.
السعر **يُلتقط وقت التعاقد**؛ تغيير كتالوج الخدمات لاحقاً لا يغيّر أي اشتراك قائم ولا أي
فاتورة سبق توليدها.

## دورة الحياة

```
مسودة ──activate──▶ نشط ──pause──▶ موقوف ──resume(بتاريخ يختاره المستخدم)──▶ نشط
                     │
                     ├─ cancel ─▶ ملغى (يحتفظ بالفواتير السابقة، يوقف الفوترة)
                     └─ (تجاوز end_date) ─▶ منتهٍ
```

- **التفعيل**: يضبط `next_billing_date` (إن كان فارغاً) على `start_date`. لا يمكن
  تفعيل اشتراك بلا بنود.
- **الإيقاف المؤقت**: يوقف الفوترة مع الاحتفاظ بكل الفواتير السابقة.
- **الاستئناف**: المستخدم يختار تاريخ الفوترة القادمة. **لا تُنشأ فواتير بأثر رجعي**؛
  أي تاريخ في الماضي يُثبَّت على اليوم.
- **الإلغاء**: يحفظ السجل (السبب/التاريخ/المُلغي) ويحتفظ بالفواتير السابقة.
- **الانتهاء**: عندما تتجاوز الفترة `end_date` يصبح الاشتراك «منتهٍ» ولا يُفوتَر.

## قرار: لا تجزئة (No Proration) في Sprint 7

الفترة الأولى دائماً دورة كاملة محتسبة من `start_date`. لا تُطبَّق أي تجزئة نسبية
للأيام. (قابل للتوسعة لاحقاً.)

## المقاييس: MRR / ARR

مقياس إداري لقيمة العقود **وليس إيراداً محاسبياً**. الإيراد يُعترف به فقط عند إصدار
فاتورة فعلية.

- التطبيع الشهري: شهري = الإجمالي، ربع سنوي = الإجمالي ÷ 3، سنوي = الإجمالي ÷ 12
  (وبشكل نسبي لبقية الدورات/الفواصل عبر `monthsPerPeriod`).
- `MRR` = مجموع القيمة الشهرية المطبَّعة للاشتراكات **النشطة** فقط.
- `ARR = MRR × 12`.

## الصلاحيات

`subscriptions.view/create/edit/activate/pause/resume/cancel/bill/manage/reports`.
المدير العام: الكل. المحاسب: عرض + فوترة + تقارير + دورة الحياة (بلا `manage`).
مدير المشاريع: `view` فقط **بدون رؤية الأسعار** (`SubscriptionPolicy::viewPrices`) حتى
لو كان عضو مشروع. الموارد البشرية والموظف: لا شيء.

## الأوامر

- `php artisan subscriptions:bill [--date=] [--dry-run] [--subscription=]` — انظر
  `RECURRING_INVOICES.md`. مُسجّل في المجدول يومياً الساعة 02:00.
