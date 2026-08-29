# النشر إلى الإنتاج (Deployment)

دليل نشر تطبيق **SuperApple ERP/CRM** على خادم إنتاج. التطبيق Laravel 13 / PHP 8.4،
واجهة عربية RTL، مع فصل تشغيلي/مالي صارم. اقرأ أيضاً [`BACKUP_RESTORE.md`](BACKUP_RESTORE.md)
قبل الإطلاق.

## متطلبات الخادم (Server requirements)

- **PHP 8.4** (الحد الأدنى المعلن في `composer.json` هو `^8.3`؛ يُنصح بـ 8.4 للإنتاج).
- إضافات PHP المطلوبة: `bcmath` (حسابات مالية دقيقة)، `ctype`، `curl`، `dom`،
  `fileinfo`، `filter`، `hash`، `mbstring`، `openssl`، `pcre`، `pdo`، `session`،
  `tokenizer`، `xml`، و`gd` (توليد/طباعة المستندات). لقاعدة MySQL أضف `pdo_mysql`.
- **قاعدة بيانات:** التطبيق يعمل على SQLite في التطوير والاختبارات، لكن يدعم MySQL.
  **يُنصح بـ MySQL 8 للإنتاج** (`utf8mb4` / `utf8mb4_unicode_ci`). MariaDB و PostgreSQL
  مدعومان أيضاً في `config/database.php`.
- **Node.js** (LTS ‑ 20 أو أحدث) + npm لبناء الأصول (Vite 8 + Tailwind 4).
- **Composer 2**.
- خادم ويب (Nginx/Apache) يوجّه إلى `public/`، مع **HTTPS**.
- عامل طابور (queue worker) وجدولة (cron) — انظر أدناه.

## متغيّرات البيئة (Environment variables)

انسخ `.env.example` إلى `.env` واضبط القيم التالية بالأسماء المذكورة (القيم أدناه
عناصر نائبة — placeholders — لا تضع أسراراً حقيقية في المستودع):

```dotenv
APP_NAME=SuperApple
APP_ENV=production
APP_DEBUG=false
APP_KEY=<generate with: php artisan key:generate>
APP_URL=https://erp.example.com

APP_LOCALE=ar
APP_FALLBACK_LOCALE=en

# قاعدة البيانات — MySQL 8 للإنتاج
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=superapple
DB_USERNAME=<db-user>
DB_PASSWORD=<db-password>

# الطابور على قاعدة البيانات (لا حاجة لـ Redis)
QUEUE_CONNECTION=database

# التخزين المؤقت والجلسات على قاعدة البيانات
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

FILESYSTEM_DISK=local
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

> **إعدادات واتساب لا تُخزَّن في `.env`.** المفاتيح التشغيلية (`whatsapp.enabled`،
> `whatsapp.provider`، `whatsapp.default_country_code`) تُدار في جدول `settings`
> عبر شاشة الإعدادات. أمّا **توكنات/مفاتيح المزوّد الحقيقي** (Meta Cloud API / 360dialog)
> فتوضَع في متغيّرات بيئة أو إعدادات آمنة على الخادم فقط، **لا في الشيفرة ولا في المستودع**
> — مثل `WHATSAPP_PHONE_NUMBER_ID=<...>` و`WHATSAPP_TOKEN=<...>`. انظر
> [`WHATSAPP.md`](WHATSAPP.md) قسم «إعداد واتساب للإنتاج».

## تسلسل أوامر النشر (Deploy commands)

من جذر التطبيق على الخادم:

```bash
# 1) اعتماديات PHP للإنتاج (بلا حزم التطوير، مع تحسين المُحمِّل التلقائي)
composer install --no-dev --optimize-autoloader

# 2) بناء أصول الواجهة
npm ci && npm run build

# 3) تشغيل الهجرات (بلا سؤال تفاعلي)
php artisan migrate --force

# 4) ربط مجلد التخزين العام (المرفقات/الملفات المرفوعة)
php artisan storage:link

# 5) تحسينات عامة
php artisan optimize

# 6) تخزين مؤقت للإعدادات والمسارات والقوالب
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> عند كل إصدار جديد أعد تشغيل خطوات 1–6 ثم أعد تشغيل عامل الطابور
> (`php artisan queue:restart`) حتى يلتقط الكود الجديد.

## عامل الطابور (Queue worker)

سائق الطابور هو **database** (`QUEUE_CONNECTION=database`). رسائل واتساب والمهام
المؤجّلة تُعالَج عبر عامل دائم. مثال وحدة **systemd**:

```ini
# /etc/systemd/system/superapple-queue.service
[Unit]
Description=SuperApple queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
WorkingDirectory=/var/www/superapple
ExecStart=/usr/bin/php artisan queue:work --queue=default --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now superapple-queue
```

بديل **Supervisor**:

```ini
[program:superapple-queue]
command=/usr/bin/php /var/www/superapple/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/var/www/superapple
user=www-data
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/superapple/storage/logs/queue.log
stopwaitsecs=3600
```

## المُجدوِل (Scheduler / cron)

أضف سطر cron واحد يشغّل مُجدوِل Laravel كل دقيقة:

```cron
* * * * * cd /var/www/superapple && php artisan schedule:run >> /dev/null 2>&1
```

المهام المجدولة المعرّفة في `routes/console.php`:

| الأمر | التوقيت | الوصف |
|------|--------|-------|
| `subscriptions:bill` | يومياً **02:00** (`withoutOverlapping`) | يصدر فواتير الاشتراكات المستحقة (idempotent — لا يفوتر أي فترة مرتين). |
| `payments:send-reminders` | يومياً **09:00** (`withoutOverlapping`) | يقيّم قواعد تذكير الدفع النشطة ويرسلها. |

## جاهزية الإطلاق (Production readiness)

قبل فتح النظام للمستخدمين نفّذ فحوص ما قبل الإطلاق (أوامر Sprint 8 قيد الإضافة):

```bash
php artisan app:health-check       # يتحقق من الاتصال بقاعدة البيانات، الطابور،
                                   # التخزين، الإعدادات المطلوبة، وحالة المُجدوِل.
php artisan app:verify-integrity   # يتحقق من سلامة الدفاتر المحاسبية والأرصدة
                                   # (توازن القيود، تطابق أرصدة العملاء).
```

عالِج أي تحذير قبل المتابعة.

## تدقيق أمان الإنتاج (Checklist)

- `APP_DEBUG=false` و`APP_ENV=production` — **إلزامي**.
- فرض **HTTPS** على كامل الموقع.
- خلف موازن حِمل/بروكسي عكسي: اضبط **الوسطاء الموثوقين** (Trusted Proxies) في
  `bootstrap/app.php` حتى تُقرأ عناوين `X-Forwarded-*` بشكل صحيح.
- صلاحيات مجلدَي `storage/` و`bootstrap/cache/` للكتابة لمستخدم خادم الويب فقط.
- **بيانات دخول الـ seeder التجريبية (كلمة المرور `password`) للتطوير فقط** — غيّرها أو
  احذفها قبل الإنتاج، ولا تشغّل `--seed` على قاعدة الإنتاج.
- فعّل نُسخ احتياطية دورية — انظر [`BACKUP_RESTORE.md`](BACKUP_RESTORE.md).
