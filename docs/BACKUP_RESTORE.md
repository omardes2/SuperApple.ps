# النسخ الاحتياطي والاستعادة (Backup & Restore)

يجب حماية **قاعدة البيانات** (كل البيانات المالية والتشغيلية) و**الملفات المرفوعة**
(المرفقات تحت `storage/app`). القيم أدناه عناصر نائبة — placeholders — لا تضع بيانات
اعتماد حقيقية في أي ملف يُلتزم به في المستودع.

## 1) نسخ قاعدة البيانات (Database backup)

للإنتاج على **MySQL 8** استخدم `mysqldump` (يفضَّل قراءة كلمة المرور من ملف `~/.my.cnf`
أو متغيّر بيئة بدل كتابتها في سطر الأوامر):

```bash
mysqldump \
  --single-transaction --quick --routines --triggers \
  -h 127.0.0.1 -u <db-user> -p'<db-password>' \
  superapple | gzip > /var/backups/superapple/db-$(date +%F-%H%M).sql.gz
```

- `--single-transaction` يعطي لقطة متّسقة دون قفل الجداول (محرّك InnoDB).
- خزّن النسخ خارج الخادم (S3/تخزين منفصل) واحتفظ بعدة أجيال.

للتطوير على **SQLite** انسخ الملف مباشرة:

```bash
cp database/database.sqlite /var/backups/superapple/db-$(date +%F).sqlite
```

## 2) نسخ الملفات والمرفقات (Storage backup)

الملفات المرفوعة (مرفقات المهام والمستندات) تعيش تحت `storage/app`. انسخ المجلد كاملاً:

```bash
tar czf /var/backups/superapple/storage-$(date +%F).tar.gz storage/app
```

> ملف `.env` يحتوي `APP_KEY` وأسراراً — احفظه بشكل آمن ومنفصل (وليس ضمن النسخة العامة).
> بدون `APP_KEY` الأصلي لن تُفكّ القيم المشفّرة.

## 3) إجراء الاستعادة (Restore)

على خادم نظيف أو للتعافي من عطل:

```bash
# أ) استعِد الشيفرة والاعتماديات
composer install --no-dev --optimize-autoloader
cp /secure/backup/.env .env        # يحوي APP_KEY وإعدادات الاتصال

# ب) استعِد قاعدة البيانات (MySQL)
gunzip < /var/backups/superapple/db-YYYY-MM-DD-HHMM.sql.gz \
  | mysql -h 127.0.0.1 -u <db-user> -p'<db-password>' superapple

# ج) استعِد الملفات
tar xzf /var/backups/superapple/storage-YYYY-MM-DD.tar.gz -C /var/www/superapple

# د) أعد بناء الكاش وربط التخزين
php artisan storage:link
php artisan optimize
php artisan config:cache route:cache view:cache
```

## 4) اختبار الاستعادة — إلزامي

**نسخة احتياطية لم تُختبَر استعادتها ليست نسخة.** جرّب الاستعادة دورياً على نسخة
**staging** منفصلة (لا الإنتاج)، وتحقق من:

- تسجيل الدخول يعمل ويظهر عدد السجلات المتوقّع.
- تشغيل `php artisan app:verify-integrity` (فحص سلامة الدفاتر والأرصدة — أمر Sprint 8)
  دون أخطاء توازن.
- ظهور المرفقات المرفوعة بشكل صحيح.

اجعل النسخ الاحتياطي مُجدولاً (cron/نظام النسخ لديك) واحتفظ بسياسة استبقاء واضحة.
