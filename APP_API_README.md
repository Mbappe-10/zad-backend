# ZAD Unified Mobile App API — Guest First

## سلوك التطبيق
- يفتح مباشرة على الصفحة الرئيسية كضيف، بلا تسجيل دخول.
- ينشئ التطبيق `guest_session_id` ويحفظه محليًا.
- يطلب إذن الموقع والإشعارات حسب إعدادات المالك.
- التصفح والمتاجر والمنتجات متاحة للضيف.
- عند تأكيد أول طلب فقط: يرسل رمز توثيق للجوال، ثم ينشئ الطلب.
- الانضمام كأسرة منتجة أو مندوب يتم من داخل التطبيق بعد إنشاء حساب/توثيق المستخدم.

## رحلة الطلب الموثقة
1. الأسرة توثق `prepared` بصورة.
2. المندوب يوثق `picked_up` بصورة.
3. المندوب يوثق `delivered` بصورة.
4. كل مرحلة تُحفظ في `order_journey_proofs` وتنتقل حالة الطلب تلقائيًا.
5. طبقة الإشعارات قابلة للربط مع Firebase؛ الإعداد `journey.notify_customer_each_stage` يتحكم بها.

## اختيار المركبة
`VehicleRecommendationService` يحدد المركبة حسب `package_size + distance_km`.
القواعد قابلة للتعديل بالكامل من إعدادات المالك تحت `delivery.vehicle_rules`.
المالك يستطيع تجاوز التوصية لكل طلب مع تسجيل السبب والمستخدم المنفذ.

## الباقات
مقفلة افتراضيًا:
- `subscriptions.enabled = false`
- `subscriptions.launch_mode = free_all_features`
يمكن تفعيلها لاحقًا من الإعدادات دون تغيير تطبيق الهاتف.

## أهم المسارات
```text
GET    /api/v1/app/bootstrap
POST   /api/v1/app/guest-sessions
PATCH  /api/v1/app/guest-sessions/{id}
POST   /api/v1/app/phone/send-code
POST   /api/v1/app/phone/verify
GET    /api/v1/app/stores
GET    /api/v1/app/stores/{store}/products
POST   /api/v1/app/orders
GET    /api/v1/app/orders/{order}
GET    /api/v1/app/orders/{order}/journey
POST   /api/v1/app/orders/{order}/journey-proof
PUT    /api/v1/owner/app/settings
PUT    /api/v1/owner/app/orders/{order}/vehicle-override
```

## ملاحظة التحقق بالجوال
الكود يجهز دورة OTP كاملة. في `local/testing` يرجع `development_code` للاختبار فقط. في الإنتاج يجب ربط دالة الإرسال بمزود SMS. لا يوجد مزود SMS مجاني دائم وموثوق للإنتاج؛ لذلك تم فصل منطق التحقق عن المزود حتى تستطيع تغييره لاحقًا. ويمكن إضافة Google/Apple من خلال الإعدادات دون تغيير مسار الضيف.

## التركيب
انسخ الحزمة فوق `zad-backend` ثم:
```bash
php artisan migrate
php artisan storage:link
php artisan optimize:clear
php artisan route:list --path=api/v1
```
