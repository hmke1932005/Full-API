# دليل التشغيل على Railway (المجموعة 6)

## 1) التخزين الدائم (Volume) — الأهم

### المشكلة
الـ container على Railway بيتبني من جديد مع كل نشر، وأي ملف اتكتب جواه بيتمسح. الكود بيكتب في أكتر من مكان:

| المسار | بيخزّن إيه |
|---|---|
| `public/uploads` | كل الملفات المرفوعة (مشاريع، رسائل، تقارير، تسجيلات اجتماعات...) |
| `storage/app/private` | لقطات وتسجيلات مراقبة الامتحانات |
| `storage/app/exam-exports` | ملفات تصدير الامتحانات (ليها صفوف في الداتابيز) |
| `storage/logs/security` | سجلات الأمان (أثر تدقيق) |

Railway بيسمح بـ **Volume واحد بس** لكل خدمة، فمش ينفع نركّب Volume لكل مسار. الحل: نركّب Volume واحد على `/data`، والتطبيق نفسه بيربط المسارات الأربعة بيه (symlinks) عند الإقلاع.

### الإعداد (مرة واحدة)
1. في خدمة **Full-API** ← تبويب **Settings** ← **Volumes**: الـ Volume `full-api-volume` اللي أضفته، خلّي **Mount Path** = `/data`.
2. في **Variables** أضف:
   ```
   PERSISTENT_STORAGE_PATH=/data
   ```
3. لو الـ logs ظهر فيها `is not writable by the app user`، أضف كمان `RAILWAY_RUN_UID=0` (الـ Volume بيتركّب بصلاحيات root).
4. اعمل Redeploy.

> بديل أبسط (مش مفضّل): تركّب الـ Volume مباشرةً على `/app/public/uploads` من غير المتغير. ده بيحمي الرفع وحده، لكن مراقبة الامتحانات وسجلات الأمان هتفضل بتتمسح.

### بيحصل إيه عند الإقلاع
- لو `/data` فعلًا Volume مركّب: أي ملفات موجودة في المسارات الأربعة بتتنقل للـ Volume (من غير ما تكتب فوق ملف موجود)، وبعدين المسار يبقى symlink.
- لو `/data` مش Volume (مثلًا وقت الـ build): **مفيش أي تغيير**. ده مقصود عشان ميحصلش ربط غلط.
- لو حصل أي تعارض أو فشل: المسار الأصلي بيتساب زي ما هو والخطأ بيتسجل في الـ logs بالشكل `[persistent-storage] ...`.

### التحقق (مهم، متثقش في الإعداد بس)
من **Railway Shell** أو `railway ssh`:
```
php artisan ops:storage
```
المفروض يطلع `OK` وكل مسار `persistent`. بعد **نشر تاني**، شغّله تاني: لازم يظهر `survived a redeploy: YES` — ده الإثبات الفعلي إن الملفات بتعدّي من نشر لنشر.

وللقيم الحقيقية بتاعة الويب (الـ CLI ممكن يستخدم إعدادات PHP مختلفة)، بحساب أدمن:
```
GET /api/v1/admin/system/storage-health
```

### ملاحظات
- الملفات اللي اتمسحت قبل كده مش هترجع. أي صف في الداتابيز بيشاور على ملف ضايع هيدي 404 للتحميل.
- Volume واحد يعني الخدمة لازم تفضل instance واحدة (Railway مش بيسمح بـ replicas مع Volume).
- حجم الـ Volume ومدة الاحتفاظ بنسخ احتياطية بيعتمدوا على خطتك في Railway — راجعهم من الـ dashboard، وفعّل الـ Backups لو خطتك بتدعمها.
- راقب المساحة: `ops:storage` بيعرض المساحة الفاضية.

---

## 2) حدود رفع PHP (50MB أو أكتر)

سياسة الرفع في التطبيق افتراضيًا **50MB** (من شاشة Security Policies). PHP نفسه لازم يسمح بنفس القيمة:

| الإعداد | المطلوب |
|---|---|
| `upload_max_filesize` | ≥ 50M |
| `post_max_size` | ≥ 50M (يفضّل أكبر شوية) |

### إيه اللي محتاج تعمله؟ يعتمد على الـ Builder
افتح **Settings → Build → Builder** في الخدمة:

- **Railpack** (الافتراضي حاليًا، بيشغّل PHP عبر FrankenPHP): الـ `php.ini` الافتراضي بتاعه فيه `upload_max_filesize = 0` و`post_max_size = 0` (يعني من غير حد على مستوى PHP)، والـ Caddy مفيهوش حد لحجم الـ request. **مفيش حاجة تتعمل.** الحد الفعلي هو سياسة التطبيق (50MB).
- **Nixpacks** (nginx + php-fpm): القيم الافتراضية صغيرة (حوالي 2M/8M) ولازم تتغير، وكمان `client_max_body_size` في nginx. الأسهل تنتقل لـ Railpack.

مهما كان الـ Builder، **اتأكد بنفسك** من `GET /api/v1/admin/system/storage-health`: لو `upload_max_filesize` و`post_max_size` فيهم `"ok": true` يبقى تمام، ولو لا هتلاقي سبب واضح في `warnings`.

لو عايز حد صريح بدل "بلا حد": حط `php.ini` في root المشروع. انتبه إنه **بيستبدل** الملف الافتراضي كله في Railpack، فلازم تنسخ الملف الافتراضي كامل من https://github.com/railwayapp/railpack/blob/main/core/providers/php/php.ini وتعدّل القيمتين بس.

---

## 3) إصلاح أمني: رفع ملفات PHP/HTML

الـ Caddyfile الافتراضي في Railpack بيشغّل `php_server` على كل الـ root، يعني أي ملف `.php` تحت `public/uploads` كان هيتنفّذ. وفئات الرفع `messages` و`ai_assistant` و`general` بتسمح برفع `php` (للمشاركة كملف كود).

اتصلح في `FileUploadService`: الامتدادات (`php`, `php3-8`, `phtml`, `pht`, `phar`, `html`, `htm`, `xhtml`, `shtml`, `cgi`, `pl`) بتتخزّن بسوفكس `.txt` (مثلًا `a1b2….php.txt`)، فبتتخدم كنص عادي ومبتتنفّذش. الاسم الأصلي بيتحفظ في `original_name` والتنزيل شغال زي ما هو.

### لازم تنضّف اللي اترفع قبل الإصلاح
الملفات اللي اترفعت قبل كده لسه بامتدادها القديم. بعد الإعداد في (1) شغّل من الـ Shell:
```
find /data/uploads -type f \( -iname '*.php*' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.pht' -o -iname '*.html' -o -iname '*.htm' -o -iname '*.shtml' \)
```
- لو مفيش نتيجة: خلاص.
- لو في نتيجة: راجعها واحذفها (أو غيّر امتدادها). وراجع الـ logs بحثًا عن طلبات غريبة على المسارات دي.

### لسه مفتوح (توصية)
ملفات `svg` المرفوعة بتتخدم من نفس الأوريجن، وده ممكن يسمح بـ XSS لو حد فتح SVG خبيث مباشرةً. التوصية: خدمة `/uploads/*` بـ header `Content-Security-Policy: sandbox` و`X-Content-Type-Options: nosniff` على مستوى الـ web server.
