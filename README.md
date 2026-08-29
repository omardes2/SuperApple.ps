# SuperApple ERP/CRM

نظام ERP/CRM داخلي متكامل لشركة تسويق وتصميم وخدمات إبداعية — يدير العملاء، المشاريع، المهام، الموظفين، الدوام، الرواتب، الفواتير، التحصيل، المصاريف، الموردين، الاشتراكات، واتساب، المحاسبة والتقارير من مكان واحد، مع **فصل كامل بين الجانب التشغيلي والمالي**.

## التقنيات
- Laravel 13 (PHP 8.4)
- Livewire 4 + Blade
- Tailwind CSS 4 (RTL)
- `spatie/laravel-permission` للأدوار والصلاحيات
- MySQL 8 (إنتاج) / SQLite (تطوير واختبارات)

## الوحدات (Modules)
العملاء (Customers)، الخدمات (Services)، المشاريع (Projects)، المهام (Tasks)،
الموظفون (Employees)، الحضور والدوام (Attendance)، الإجازات (Leaves)،
الرواتب/مسيّرات (Payroll)، السُلَف (Advances)، عروض الأسعار (Quotations)،
الفواتير (Invoices)، الدفعات (Payments)، أسعار الصرف (Exchange Rates)،
المحاسبة (Accounting)، الصناديق والبنوك (Cash & Banks)، المصاريف (Expenses)،
الموردون (Suppliers)، الاشتراكات (Subscriptions)، الفواتير الدورية (Recurring Invoices)،
واتساب (WhatsApp)، تذكيرات الدفع (Payment Reminders)، الإشعارات (Notifications)،
سجل العمليات (Audit Logs).

## المتطلبات (Requirements)
- PHP 8.4 (الحد الأدنى `^8.3`) مع الإضافات: `bcmath, ctype, curl, dom, fileinfo,
  filter, mbstring, openssl, pdo, tokenizer, xml, gd` (و`pdo_mysql` لقاعدة MySQL).
- Composer 2، Node.js 20+ و npm.
- MySQL 8 للإنتاج (أو SQLite للتطوير والاختبارات).

## القواعد المالية الثابتة
- العملة المحاسبية الأساسية: **ILS**، عملة فواتير العملاء: **USD**، رصيد العميل الرسمي بالدولار.
- الفاتورة تُثبّت سعر الصرف عند الإصدار ولا يتغير. كل دفعة تسجل سعر صرفها وتُحوّل إلى USD. فروق الصرف تُسجّل كـ Exchange Gain/Loss.
- لا حذف نهائي للعمليات المالية المعتمدة (Void/Cancel/Reverse/Adjustment).
- الموظف العادي لا يرى أي بيانات مالية — يُفرض على مستوى Policies/Permissions لا الواجهة فقط.

راجع [`docs/`](docs/) للمعمارية وتصميم قاعدة البيانات وقواعد العملات ومصفوفة الصلاحيات وخطة التنفيذ.

## التشغيل
```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite      # للتطوير
php artisan migrate:fresh --seed
npm install && npm run build        # أو: npm run dev
php artisan serve
```

## حسابات التجربة (كلمة المرور للجميع: `password`)

> ⚠️ **للتطوير فقط.** حسابات وكلمات مرور الـ seeder التجريبية (`password`) مخصّصة
> للتطوير والعرض فقط. **غيّرها أو احذفها قبل الإنتاج**، ولا تشغّل `--seed` على قاعدة
> بيانات الإنتاج.

| الدور | البريد |
|------|--------|
| Super Admin | admin@superapple.ps |
| General Manager | gm@superapple.ps |
| Accountant | accountant@superapple.ps |
| HR Manager | hr@superapple.ps |
| Project Manager | pm@superapple.ps |
| Team Leader | lead@superapple.ps |
| Employee | employee@superapple.ps |

## الاختبارات
```bash
php artisan test          # PHPUnit (SQLite in-memory)
./vendor/bin/pint         # تنسيق الكود
```

## النشر للإنتاج (Production)
- **قائمة الإطلاق خطوة بخطوة:** [`docs/PRODUCTION_CHECKLIST.md`](docs/PRODUCTION_CHECKLIST.md) (BEFORE/DEPLOY/AFTER/BEFORE USERS/WHATSAPP).
- **دليل النشر الكامل:** [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — متطلبات الخادم، متغيّرات البيئة، أوامر النشر، عامل الطابور، المُجدوِل، البذر الإنتاجي، التراجع.
- **النسخ الاحتياطي:** [`docs/BACKUP_RESTORE.md`](docs/BACKUP_RESTORE.md). **عيّنات الخادم:** [`docs/deploy/`](docs/deploy).
- **سكربتات:** `scripts/deploy-production.sh` (نشر آمن) و`scripts/verify-production.sh` (تحقّق غير مُخرِّب).
- **البذر الإنتاجي:** `php artisan db:seed --class=ProductionSeeder --force` (بيانات أساسية فقط، بلا عرض تجريبي)، ثم أول مدير: `php artisan app:create-admin` (إدخال مخفي، لا كلمات مرور ضعيفة).

فهرس التوثيق الكامل في [`docs/README.md`](docs/README.md).

## حالة التنفيذ
- **Sprint 0 (مكتمل):** المصادقة، الأدوار والصلاحيات، تخطيط RTL للإدارة والموظف، الإعدادات، سجل العمليات (Audit Log)، ترقيم المستندات، طبقة الخدمات الأساسية، بيانات تجريبية، واختبارات صلاحيات ومالية أساسية.
- **Sprint 1 (مكتمل):** الأقسام، الموظفون (ملف تشغيلي منفصل عن حساب الدخول، بلا بيانات مالية)، الحضور والدوام (تسجيل حضور/انصراف، حساب التأخير والعمل الإضافي، تعديلات إدارية)، الإجازات (طلب/اعتماد/رفض/إلغاء/عكس مع مزامنة الدوام)، الإشعارات. راجع [`docs/HR.md`](docs/HR.md).
- **Sprint 2 (مكتمل):** العملاء (CRM بلا بريد إلكتروني)، كتالوج الخدمات (حماية الأسعار على مستوى الباك-إند)، المشاريع (أعضاء، تقدم مشتق)، المهام مع Workflow كامل (بدء/مراجعة/طلب تعديلات/اعتماد/إعادة فتح) وتعليقات وقوائم تحقق ومرفقات وسجل حالة. فصل تشغيلي/مالي حقيقي عبر Query Scoping. راجع [`docs/OPERATIONS.md`](docs/OPERATIONS.md).
- **Sprint 3 (مكتمل):** أسعار الصرف، عروض الأسعار، الفواتير — حسابات آمنة عشرياً (brick/math)، تثبيت سعر الصرف عند الإصدار، فاتورة غير قابلة للتعديل بعد الإصدار، تحويل العرض المقبول إلى فاتورة، وطباعة A4. راجع [`docs/INVOICES.md`](docs/INVOICES.md) و[`docs/QUOTATIONS.md`](docs/QUOTATIONS.md) و[`docs/CURRENCY.md`](docs/CURRENCY.md).
- Sprints 4–8: الدفعات وفروق الصرف، المصاريف/المحاسبة، الرواتب، الاشتراكات/واتساب، التقارير واللوحات — راجع [`docs/PLAN.md`](docs/PLAN.md).

الاختبارات: **149 اختبار / 478 تأكيد** ناجحة.
