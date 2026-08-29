# دليل توثيق SuperApple ERP/CRM

**SuperApple** نظام ERP/CRM داخلي لشركة تسويق وتصميم وخدمات إبداعية: يدير العملاء
والمشاريع والمهام والموظفين والدوام والرواتب والفواتير والتحصيل والمصاريف والموردين
والاشتراكات وواتساب والمحاسبة والتقارير من مكان واحد، مع **فصل كامل بين الجانب التشغيلي
والمالي**. التطبيق مبني على Laravel 13 / PHP 8.4، واجهة عربية RTL (Livewire 4 + Tailwind 4).

هذا الفهرس يجمع كل ملفات التوثيق مصنّفة حسب المجال.

## المعمارية والبيانات (Architecture & Data)

- [`ARCHITECTURE.md`](ARCHITECTURE.md) — المعمارية العامة وطبقات النظام.
- [`DATABASE.md`](DATABASE.md) — تصميم قاعدة البيانات والمخطط.
- [`PERMISSIONS.md`](PERMISSIONS.md) — الأدوار ومصفوفة الصلاحيات.
- [`PLAN.md`](PLAN.md) — خطة التنفيذ والـ Sprints.

## المالية والمحاسبة (Financial & Accounting)

- [`ACCOUNTING.md`](ACCOUNTING.md) — المحاسبة والقيود.
- [`CHART_OF_ACCOUNTS.md`](CHART_OF_ACCOUNTS.md) — دليل الحسابات.
- [`CURRENCY.md`](CURRENCY.md) — قواعد العملات وسعر الصرف (ILS/USD).
- [`CUSTOMER_BALANCES.md`](CUSTOMER_BALANCES.md) — أرصدة العملاء.
- [`CASH_BANKS.md`](CASH_BANKS.md) — الصناديق والبنوك.

## الوحدات (Modules)

- [`HR.md`](HR.md) — الموارد البشرية: الأقسام، الموظفون، الحضور، الإجازات.
- [`OPERATIONS.md`](OPERATIONS.md) — العملاء، الخدمات، المشاريع، المهام.
- [`QUOTATIONS.md`](QUOTATIONS.md) — عروض الأسعار.
- [`INVOICES.md`](INVOICES.md) — الفواتير.
- [`PAYMENTS.md`](PAYMENTS.md) — الدفعات والتحصيل.
- [`RECURRING_INVOICES.md`](RECURRING_INVOICES.md) — الفواتير الدورية.
- [`SUBSCRIPTIONS.md`](SUBSCRIPTIONS.md) — الاشتراكات.
- [`EXPENSES.md`](EXPENSES.md) — المصاريف.
- [`SUPPLIERS.md`](SUPPLIERS.md) — الموردون.
- [`PAYROLL.md`](PAYROLL.md) — مسيّرات الرواتب.
- [`SALARIES.md`](SALARIES.md) — الرواتب.
- [`ADVANCES.md`](ADVANCES.md) — سُلَف الموظفين.
- [`WHATSAPP.md`](WHATSAPP.md) — قناة واتساب الصادرة (يشمل إعداد الإنتاج).
- [`PAYMENT_REMINDERS.md`](PAYMENT_REMINDERS.md) — تذكيرات الدفع.

## التشغيل والنشر (Operations & Deployment)

- [`OPERATIONS.md`](OPERATIONS.md) — الجانب التشغيلي اليومي.
- [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md) — قائمة الإطلاق خطوة بخطوة.
- [`DEPLOYMENT.md`](DEPLOYMENT.md) — دليل النشر إلى الإنتاج + التراجع + أدوات الإطلاق.
- [`BACKUP_RESTORE.md`](BACKUP_RESTORE.md) — النسخ الاحتياطي والاستعادة.
- [`deploy/`](deploy) — عيّنات الخادم: `env.production.sample`، `nginx.conf.sample`، `supervisor-queue.conf.sample`، `systemd-queue.service.sample`، `scheduler-cron.sample`، `whatsapp-golive.md`.

> للبدء السريع محلياً راجع `README.md` في جذر المستودع. للإطلاق إلى الإنتاج ابدأ من
> [`PRODUCTION_CHECKLIST.md`](PRODUCTION_CHECKLIST.md).
