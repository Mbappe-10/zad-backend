<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_documents')) {
            Schema::create('policy_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 80)->unique();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('policy_versions')) {
            Schema::create('policy_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_document_id')->constrained()->cascadeOnDelete();
                $table->string('version', 30);
                $table->string('title_ar', 180);
                $table->string('title_en', 180);
                $table->longText('content_ar');
                $table->longText('content_en');
                $table->string('status', 20)->default('draft');
                $table->boolean('requires_acceptance')->default(false);
                $table->text('change_summary')->nullable();
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['policy_document_id', 'version']);
                $table->index(['policy_document_id', 'status']);
            });
        }

        $now = now();

        $documents = [
            [
                'key' => 'terms',
                'sort_order' => 10,
                'requires_acceptance' => true,
                'title_ar' => 'شروط الاستخدام',
                'title_en' => 'Terms of Use',
                'content_ar' => <<<'AR'
1. نطاق الشروط
باستخدام منصة زاد سينك يوافق المستخدم على هذه الشروط وعلى السياسات المنشورة داخل التطبيق. تقدم المنصة سوقًا تقنيًا يربط العميل بالأسرة المنتجة ومندوب التوصيل، وتدير رحلة الطلب والدفع والدعم وفق الحالة الفعلية للطلب.

2. بيانات المستخدم والطلب
يلتزم المستخدم بإدخال رقم جوال صحيح وموقع توصيل دقيق وملاحظات واضحة. يتحمل المستخدم مسؤولية التأخير أو تعذر التسليم الناتج عن بيانات غير صحيحة أو ناقصة.

3. الدفع وتأكيد الطلب
لا يصبح الطلب مؤكدًا ولا يصل إلى الأسرة المنتجة أو المندوب إلا بعد تأكيد بوابة الدفع نجاح العملية. ظهور محاولة الدفع أو خصم معلق لا يعد وحده تأكيدًا نهائيًا للطلب.

4. التواصل المسموح
يكون التواصل التشغيلي المتعلق بالاستلام والتسليم فقط مع مندوب التوصيل المعيّن ومن خلال وسائل المنصة. أما المشكلات والشكاوى والتعويضات فتتم حصريًا عبر دعم زاد سينك.

5. منع التواصل المباشر والتحايل
يُمنع طلب أو مشاركة رقم الجوال أو حسابات التواصل الاجتماعي أو الروابط أو أي وسيلة اتصال مباشرة بين العميل والأسرة المنتجة، سواء في البث أو التعليقات أو الملاحظات أو الرسائل. كما يمنع الاتفاق على شراء أو دفع أو تسليم خارج المنصة أو محاولة تجاوز رسومها أو أنظمتها.

6. البث والمحتوى
يخصص البث لعرض المنتج أو التجهيز المسموح به، ولا يجوز استخدامه لتبادل بيانات التواصل أو طلب تحويل مالي مباشر أو التسويق خارج زاد سينك. يحق للمنصة إيقاف البث أو المحتوى المخالف وحفظ الأدلة اللازمة للتحقيق.

7. الاستخدام المحظور
يمنع الاحتيال، الإساءة، التهديد، التحرش، انتحال الهوية، إساءة استخدام البلاغات، التلاعب بالتقييمات، إدخال برمجيات ضارة، أو محاولة الوصول غير المصرح به إلى حسابات أو بيانات المنصة.

8. الإجراءات عند المخالفة
تعد مخالفة هذه البنود مخالفة تعاقدية لسياسات المنصة، وقد ينتج عنها تحذير أو تقييد خصائص الحساب أو تعليق الحساب أو إلغاؤه أو إلغاء الطلب والتحقيق فيه. وإذا شكل التصرف مخالفة نظامية مستقلة فيجوز إحالة الأمر إلى الجهة المختصة.

9. المسؤولية والتعويض
تعالج أخطاء المنتجات والتوصيل والدفع وفق السياسة المرتبطة بكل حالة. لا تتحمل المنصة التأخير الناتج عن القوة القاهرة أو الظروف الخارجة عن السيطرة المعقولة، مع التزامها باتخاذ الإجراءات التشغيلية الممكنة.

10. التحديث والقانون المطبق
يجوز تحديث هذه الشروط مع إظهار رقم الإصدار وتاريخ السريان، ويطلب قبول جديد عند التعديلات الجوهرية. تخضع العلاقة للأنظمة المعمول بها في المملكة العربية السعودية.
AR,
                'content_en' => <<<'EN'
1. Scope
By using ZADSYNC, the user accepts these terms and the policies published in the application. ZADSYNC is a technology marketplace connecting customers, productive families and delivery drivers.

2. User and order data
The user must provide a valid phone number, an accurate delivery location and clear notes. The user is responsible for delay or failed delivery caused by incorrect or incomplete information.

3. Payment and confirmation
An order is not confirmed or released to a productive family or driver until the payment gateway confirms successful payment.

4. Permitted communication
Delivery-related communication is limited to the assigned driver through platform-provided channels. Problems, complaints and compensation requests must be handled through ZADSYNC Support.

5. No direct contact or circumvention
Customers and productive families must not request or share phone numbers, social accounts, links or other direct contact details through live streams, comments, notes or messages. Off-platform purchasing, payment, delivery or fee circumvention is prohibited.

6. Live content
Live streaming is for permitted product display and preparation only. It must not be used to exchange contact details, request direct transfers or market outside ZADSYNC.

7. Prohibited conduct
Fraud, abuse, threats, harassment, impersonation, report abuse, rating manipulation, malicious software and unauthorized access are prohibited.

8. Enforcement
A breach may result in a warning, feature restriction, suspension, account closure, order cancellation or investigation. Independently unlawful conduct may be referred to the competent authority.

9. Liability
Product, delivery and payment incidents are handled under the applicable policy. ZADSYNC is not responsible for force-majeure events beyond reasonable control.

10. Updates and governing law
Material updates may require renewed acceptance. These terms are governed by the laws of the Kingdom of Saudi Arabia.
EN,
            ],
            [
                'key' => 'privacy',
                'sort_order' => 20,
                'requires_acceptance' => true,
                'title_ar' => 'سياسة الخصوصية',
                'title_en' => 'Privacy Policy',
                'content_ar' => <<<'AR'
1. البيانات التي نجمعها
نجمع الحد الأدنى اللازم لتشغيل الخدمة، ويشمل رقم الجوال الموثق، الاسم الاختياري، موقع وعنوان كل طلب، بيانات الطلب وحالة الدفع، رسائل الدعم والمرفقات، والسجلات التقنية والأمنية الضرورية.

2. بيانات الدفع
لا تخزن زاد سينك رقم البطاقة الكامل أو رمزها السري. تعالج بوابة الدفع المرخصة بيانات البطاقة، بينما تحتفظ المنصة بمعرف العملية ومبلغها وحالتها والمرجع الآمن اللازم للمطابقة والاسترجاع.

3. أغراض الاستخدام
تستخدم البيانات لإنشاء الطلب وتنفيذه، التحقق من الدفع، اختيار المندوب، تقديم الدعم، منع الاحتيال، تحسين الخدمة، الوفاء بالالتزامات النظامية وإدارة النزاعات.

4. مشاركة البيانات حسب الحاجة
تصل للأسرة المنتجة فقط بيانات تجهيز الطلب اللازمة، ويصل للمندوب فقط ما يلزم للاستلام والتوصيل. لا يظهر للعميل عنوان مقر الأسرة المنتجة أو موقعها الإداري. وقد تشارك البيانات مع مزودي الدفع والخدمات والجهات المختصة عند وجود مسوغ نظامي.

5. الموقع والعناوين
يحفظ موقع التوصيل كنسخة مرتبطة بالطلب، ولا يفرض على العميل إنشاء دفتر عناوين دائم. تحدد مدة الاحتفاظ ببيانات الموقع وفق الحاجة التشغيلية والأمنية.

6. الحماية والاحتفاظ
تطبق ضوابط وصول وسجلات تدقيق وإجراءات حماية مناسبة. لا يحتفظ بالبيانات مدة أطول من الحاجة، مع استثناء السجلات المالية والأمنية أو بيانات النزاعات التي يلزم الاحتفاظ بها نظامًا.

7. حقوق صاحب البيانات
يمكن للمستخدم طلب الاطلاع أو التصحيح أو الحذف أو سحب الموافقة في الحدود التي تسمح بها الأنظمة وطبيعة الالتزامات القائمة، وذلك من خلال الدعم.

8. التحديث والتواصل
يعرض التطبيق النسخة المنشورة وتاريخ سريانها. توجه الاستفسارات المتعلقة بالخصوصية إلى بيانات التواصل المنشورة في صفحة اتصل بنا.
AR,
                'content_en' => <<<'EN'
ZADSYNC collects only data needed to operate the service, including a verified phone number, optional display name, per-order delivery location, order and payment status, support messages, attachments and necessary technical/security logs. Full card data is processed by the licensed payment provider and is not stored by ZADSYNC. Data is used to fulfil orders, verify payments, assign delivery, provide support, prevent fraud and comply with legal obligations. Productive families and drivers receive only the data necessary for their role; the family's administrative location is not shown to customers. Data is retained only as needed, subject to financial, security and dispute retention requirements. Users may request access, correction or deletion where permitted by applicable Saudi law.
EN,
            ],
            [
                'key' => 'payment_refund',
                'sort_order' => 30,
                'requires_acceptance' => true,
                'title_ar' => 'سياسة الدفع والاسترجاع',
                'title_en' => 'Payment and Refund Policy',
                'content_ar' => <<<'AR'
1. يعرض قبل الدفع سعر المنتجات ورسوم التوصيل والخصم والضريبة إن وجدت والإجمالي النهائي.
2. لا يؤكد الطلب إلا بعد تحقق خادم زاد سينك من نجاح العملية لدى بوابة الدفع.
3. عند فشل الدفع لا يصل الطلب للأسرة أو المندوب، ولا تنشأ محفظة أو تسوية أو تتبع تشغيلي للطلب.
4. عند الرفض أو انتهاء الجلسة يمكن إعادة المحاولة أو تغيير وسيلة الدفع أو العودة للسلة.
5. عند تكرار الخصم أو وجود عملية غير معروفة يرفع بلاغ مالي يتضمن رقم العملية والمبلغ ووقت الدفع دون إرسال بيانات البطاقة السرية.
6. يكون الاسترجاع إلى وسيلة الدفع الأصلية متى أمكن. يختلف وقت ظهور المبلغ حسب البنك وبوابة الدفع ولا يعد بدء الاسترجاع وعدًا بوقت مصرفي ثابت.
7. يرتبط استحقاق الاسترجاع بحالة الطلب وسياسة الإلغاء وطبيعة المنتج ونتيجة التحقيق.
8. لا يطلب موظفو زاد سينك من العميل إرسال رمز البطاقة أو كلمة المرور أو رمز التحقق البنكي.
AR,
                'content_en' => <<<'EN'
The customer sees products, delivery, discount, tax if applicable and the final total before payment. An order is confirmed only after server-side verification from the payment gateway. Failed payments do not create operational orders, wallet entries, settlements or tracking. Refunds are returned to the original payment method where possible; bank processing time varies. Eligibility depends on order status, cancellation rules, product type and investigation outcome. ZADSYNC staff will never request a card PIN, password or bank verification code.
EN,
            ],
            [
                'key' => 'cancellation_compensation',
                'sort_order' => 40,
                'requires_acceptance' => true,
                'title_ar' => 'سياسة الإلغاء والتعويض',
                'title_en' => 'Cancellation and Compensation Policy',
                'content_ar' => <<<'AR'
1. قبل الدفع يمكن تعديل السلة أو إلغاؤها دون إنشاء طلب تشغيلي.
2. بعد الدفع وقبل قبول الأسرة يمكن تقديم طلب إلغاء واسترجاع.
3. بعد قبول الأسرة وقبل بدء التجهيز يعالج الإلغاء وفق الوقت والتكلفة الفعلية.
4. بعد بدء تجهيز منتج طازج أو مصنوع حسب الطلب قد يتعذر الإلغاء، ما لم يوجد خطأ أو عيب أو إخلال من الأسرة أو المنصة.
5. بعد استلام المندوب للطلب لا يتم الإلغاء تلقائيًا، بل ينتقل الطلب للدعم لاتخاذ القرار وفق الحالة.
6. في حال منتج خاطئ أو ناقص أو تالف أو غير آمن، يرفع العميل بلاغًا خلال المدة الظاهرة في التطبيق مع صور أو أدلة عند الإمكان.
7. قد يكون الحل استبدالًا أو استرجاعًا كاملًا أو جزئيًا أو رصيدًا تعويضيًا، حسب سبب المشكلة والجزء المتأثر ونتيجة التحقيق.
8. لا يستحق التعويض عن معلومات توصيل خاطئة قدمها العميل أو عدم استجابته ضمن مدة التسليم المعقولة.
AR,
                'content_en' => <<<'EN'
Before payment, the cart may be changed or cancelled. After payment and before family acceptance, a cancellation and refund may be requested. Once preparation begins, fresh or made-to-order items may be non-cancellable unless there is a product or service failure. After driver pickup, support must review the case. Wrong, missing, damaged or unsafe items should be reported within the period shown in the app with available evidence. The remedy may be replacement, full or partial refund, or platform credit, depending on investigation.
EN,
            ],
            [
                'key' => 'delivery',
                'sort_order' => 50,
                'requires_acceptance' => false,
                'title_ar' => 'سياسة التوصيل',
                'title_en' => 'Delivery Policy',
                'content_ar' => <<<'AR'
1. يختار النظام مركبة التوصيل بناءً على حجم ووزن وحساسية الطلب والمسافة والمنطقة وسعة الصندوق وتوفر المندوب ومتطلبات السلامة.
2. السكوتر مخصص عادة للطلبات الخفيفة والصغيرة والمسافات القصيرة، والدباب للطلبات الصغيرة والمتوسطة، والسيارة للطلبات الكبيرة أو المتعددة أو الحساسة والمسافات الأطول.
3. الحدود الدقيقة للمسافة والوزن والسعة ورسوم المركبات إعدادات تشغيلية قابلة للتحديث، ويعرض رسم التوصيل النهائي للعميل قبل الدفع.
4. يجوز تغيير نوع المركبة لأسباب السلامة أو التوفر دون زيادة المبلغ بعد الدفع إلا بموافقة العميل.
5. يشترط وجود صندوق توصيل مناسب للمركبة وفق متطلبات زاد سينك، والمحافظة على المنتج أثناء النقل.
6. التواصل بين العميل والمندوب مخصص للتسليم فقط. لا يحق للعميل طلب موقع الأسرة أو بيانات تواصلها.
7. يلتزم العميل بالتواجد والاستجابة وتوفير نقطة تسليم قابلة للوصول. تعالج حالات تعذر التسليم من خلال الدعم.
AR,
                'content_en' => <<<'EN'
The delivery vehicle is selected using order size, weight, sensitivity, distance, zone, box capacity, availability and safety requirements. Scooters generally handle light short-distance orders, motorcycles handle small-to-medium orders, and cars handle larger, multiple, fragile or longer-distance orders. Exact thresholds and fees are configurable and the final delivery fee is shown before payment. Customer-driver communication is for delivery only; a customer may not request the family's location or contact details.
EN,
            ],
            [
                'key' => 'account_deletion',
                'sort_order' => 60,
                'requires_acceptance' => false,
                'title_ar' => 'سياسة حذف الحساب',
                'title_en' => 'Account Deletion Policy',
                'content_ar' => <<<'AR'
1. يقدم طلب حذف الحساب من التطبيق ويؤكد برمز تحقق يرسل إلى رقم الجوال المرتبط بالحساب.
2. قد يؤجل الإغلاق عند وجود طلب نشط أو بلاغ مفتوح أو تسوية أو سحب مالي معلق أو التزام نظامي قائم.
3. عند اكتمال المتطلبات تلغى رموز الدخول وتوقف إمكانية استخدام الحساب.
4. تحذف أو تجهل البيانات التي لم تعد هناك حاجة مشروعة للاحتفاظ بها.
5. قد يحتفظ بالسجلات المالية وسجلات الطلبات والأمن والنزاعات للمدة المطلوبة نظامًا أو اللازمة لحماية الحقوق.
6. يرسل إشعار باستلام طلب الحذف وإشعار آخر عند اكتماله أو عند الحاجة إلى إجراء إضافي.
AR,
                'content_en' => <<<'EN'
Account deletion is requested in the app and verified using the linked phone number. Closure may be delayed while an order, complaint, settlement, payout, dispute or legal obligation remains active. Access tokens are revoked when processing is complete. Data no longer needed is deleted or anonymized, while financial, order, security and dispute records may be retained when legally required or necessary to protect rights.
EN,
            ],
            [
                'key' => 'complaints_support',
                'sort_order' => 70,
                'requires_acceptance' => false,
                'title_ar' => 'سياسة البلاغات والشكاوى',
                'title_en' => 'Complaints and Support Policy',
                'content_ar' => <<<'AR'
1. ينشأ لكل بلاغ رقم تذكرة واضح مرتبط برقم الجوال، ويمكن ربطه بطلب أو عملية دفع أو حساب أسرة أو مندوب.
2. يختار مقدم البلاغ التصنيف ويكتب التفاصيل ويرفق الأدلة المتاحة دون مشاركة بيانات بطاقات أو كلمات مرور.
3. تمر التذكرة بحالات: جديدة، قيد المراجعة، بانتظار المستخدم، محلولة، مغلقة.
4. تعرض مدة الرد المستهدفة في التطبيق، ويجوز تمديدها عند الحاجة إلى تحقيق مالي أو تشغيلي مع إشعار مقدم الطلب.
5. يمكن تصعيد الشكوى إلى الإدارة أو المالية أو الحوكمة حسب طبيعتها.
6. يمنع تقديم بلاغات كيدية أو متكررة بقصد الإساءة أو تعطيل الخدمة، مع عدم الإخلال بحق المستخدم في الاعتراض المشروع.
7. تحفظ المحادثات والمرفقات وفق سياسة الخصوصية وفترات الاحتفاظ المعتمدة.
AR,
                'content_en' => <<<'EN'
Every complaint receives a ticket number linked to a verified phone number and may be associated with an order, payment, family or driver. The requester selects a category, provides details and may attach evidence, but must not share card secrets or passwords. Ticket states include new, under review, waiting for user, resolved and closed. Matters may be escalated to operations, finance, management or governance. Abusive or malicious reports are prohibited without limiting legitimate complaints and appeals.
EN,
            ],
        ];

        foreach ($documents as $documentData) {
            $documentId = DB::table('policy_documents')->where('key', $documentData['key'])->value('id');

            if (! $documentId) {
                $documentId = DB::table('policy_documents')->insertGetId([
                    'key' => $documentData['key'],
                    'sort_order' => $documentData['sort_order'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $versionExists = DB::table('policy_versions')
                ->where('policy_document_id', $documentId)
                ->where('version', '1.0')
                ->exists();

            if (! $versionExists) {
                DB::table('policy_versions')->insert([
                    'policy_document_id' => $documentId,
                    'version' => '1.0',
                    'title_ar' => $documentData['title_ar'],
                    'title_en' => $documentData['title_en'],
                    'content_ar' => $documentData['content_ar'],
                    'content_en' => $documentData['content_en'],
                    'status' => 'published',
                    'requires_acceptance' => $documentData['requires_acceptance'],
                    'change_summary' => 'الإصدار الأول المعتمد لمركز سياسات زاد سينك.',
                    'effective_at' => $now,
                    'published_at' => $now,
                    'created_by' => null,
                    'published_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('policy_documents');
    }
};