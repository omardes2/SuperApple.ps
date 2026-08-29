# SuperApple ERP/CRM

نظام ERP/CRM داخلي متكامل لشركة تسويق وتصميم وخدمات إبداعية — يدير العملاء، المشاريع، المهام، الموظفين، الدوام، الرواتب، الفواتير، التحصيل، المصاريف، الموردين، الاشتراكات، واتساب، المحاسبة والتقارير من مكان واحد، مع **فصل كامل بين الجانب التشغيلي والمالي**.

## التقنيات
- Laravel 13 (PHP 8.4)
- Livewire 4 + Blade
- Tailwind CSS 4 (RTL)
- `spatie/laravel-permission` للأدوار والصلاحيات
- MySQL 8 (إنتاج) / SQLite (تطوير واختبارات)

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

## حالة التنفيذ
- **Sprint 0 (مكتمل):** المصادقة، الأدوار والصلاحيات، تخطيط RTL للإدارة والموظف، الإعدادات، سجل العمليات (Audit Log)، ترقيم المستندات، طبقة الخدمات الأساسية، بيانات تجريبية، واختبارات صلاحيات ومالية أساسية.
- Sprints 1–8: الموارد البشرية، CRM/المشاريع/المهام، الفواتير وعروض الأسعار، الدفعات وفروق الصرف، المصاريف/المحاسبة، الرواتب، الاشتراكات/واتساب، التقارير واللوحات — راجع [`docs/PLAN.md`](docs/PLAN.md).
