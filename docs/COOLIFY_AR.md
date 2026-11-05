# Superfix ADMS — خطوات اختبار Coolify (النسخة التجريبية)

**تحذير:** تجهيز Docker لا يثبت جاهزية النظام للتشغيل العام. لا توجه أي جهاز UA300 إلى السيرفر قبل اختبار أمان الاتصال وتوافق البروتوكول.

## البنية المقترحة
- لا تلمس تطبيق `att.superfix.ae` الحالي ولا SQL Server 2025.
- أنشئ مشروع Coolify مستقل `Superfix ADMS Test`.
- أضف MySQL 8 بقاعدة مستقلة ومجلد بيانات دائم (Persistent Storage) وشبكة Docker داخلية. لا تفتح MySQL للإنترنت.
- أضف تطبيق GitHub من `supfix2015-max/superfix-adms-server`, branch `master`, Build Pack `Dockerfile`, Port `8080`.
- أثناء الإعداد اختبر من الشبكة الخاصة فقط؛ لا تضف DNS عمومي قبل الحماية واختبار تسجيل الدخول.
- يمكن استخدام `adms-test.superfix.ae` لاحقاً، مع HTTPS + حماية MFA/Zero Trust على صفحات الإدارة.

## Runtime environment variables
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://adms-test.superfix.ae` (فقط بعد تأمين الدومين)
- `APP_KEY=base64:...` مفتاح Laravel يتم توليده محلياً بأمر `php artisan key:generate --show`، ولا يضاف إلى GitHub.
- `DB_CONNECTION=mysql`
- `DB_HOST=<اسم خدمة MySQL الداخلية>`
- `DB_PORT=3306`
- `DB_DATABASE=<قاعدة جديدة>`
- `DB_USERNAME=<مستخدم جديد>`
- `DB_PASSWORD=<كلمة قوية جديدة>`
- `SESSION_DRIVER=file`, `CACHE_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=database`.
- `SESSION_SECURE_COOKIE=true` مع HTTPS فقط.
- `ZKTECO_AUTO_REGISTER_DEVICES=false`
- `ZKTECO_AUTO_PULL_EMPLOYEES_ON_CONTACT=false`
- `ADMS_PILOT_ENABLED=false`
- `ADMS_PILOT_SERIALS=`, `ADMS_PILOT_ALLOWED_IPS=`
- `INITIAL_ADMIN_EMAIL=<بريد المدير>`, `INITIAL_ADMIN_PASSWORD=<20+ characters>` للأول مرة فقط.

## تهيئة قاعدة جديدة
بعد النشر والتأكد من الاتصال، افتح Terminal الخاص بالتطبيق:
```sh
php artisan migrate --force
php artisan db:seed --class=UserSeeder --force
php artisan route:list
```
**لا تستخدم** `migrate:fresh` مع بيانات حقيقية. أبقِ `ADMS_SEED_DEMO_DATA=false` في الإنتاج. بعد إنشاء حساب المدير احذف `INITIAL_ADMIN_PASSWORD` من متغيرات Coolify وأعد نشر التطبيق.

## فحوص قبل فتح واجهة الاختبار
1. الزائر غير المسجل: `/admin/dashboard` يجب تحويله إلى `/login`.
2. `/register` و `/api/device/register`: 404.
3. `/iclock/cdata?SN=...`: 403؛ لأن ADMS مغلق.
4. لا تتوفر صفحة `/pma.php`؛ 404.
5. حساب المدير فقط يفتح الواجهة؛ باقي الحسابات 403.
6. لا يوجد سيريال أو قالب بصمة حقيقي في بيانات التجربة.
7. شغّل `php artisan test --filter=SuperfixSecurityGateTest` على نسخة التطوير.
8. لا يشترط عنوان IP عام للفرع: ADMS Push اتصال صادر من الجهاز إلى Hostinger، ويمكن تغيير الراوتر والشبكة. عند تفعيل اختبار جهاز واحد: `ADMS_PILOT_SERIALS=<actual serial>`, و`ADMS_PILOT_ALLOWED_IPS=` فارغة. لكن الرقم التسلسلي وحده ليس مصادقة، والافتراضي `ZKTECO_REQUIRE_TOKEN=true`، ويجب التأكد من قدرة UA300 Pro على تقديم التوكن أو اعتماد بوابة اتصال موثقة قبل إرسال الأوامر/البيانات البيومترية.

**حافظ على فصل MySQL الجديدة عن SQL Server 2025 ونسخة قاعدة `Att2000_V4`.**

## فروع DU بدون Public IP
- أجهزة ZKTeco تتصل **Outbound** إلى `https://att1.superfix.ae`، وليس العكس؛ لا تحتاج Port Forwarding ولا FortiGate.
- **Gate** الشبكي اختيارى: لا تضبط IP ثابت على `ADMS_PILOT_ALLOWED_IPS`، لأن WAN IP يتغير.
- السيريال المحدد فقط يُقبل عند تفعيل `ADMS_PILOT_ENABLED=true`. **هذا ليس ضمان هوية الجهاز**، وتوثيق الطرف النهائي يعتمد على Device Token أو حل موثّق آخر.
- لا تعطل `ZKTECO_REQUIRE_TOKEN` على سيرفر عام بشكل دائم. إن لم يدعم الجهاز تمرير التوكن الأصلي، نحتاج حل pairing/gateway مناسب أو تجربة محكومة مع حصر الأوامر وتجنب البيانات الحساسة.
- نبقي `ADMS_PILOT_ENABLED=false` حالياً إلى اكتمال اختبار المصادقة.
