<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandingSetting;
use App\Models\BrandingSettingVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class BrandingSettingController extends Controller
{
    /**
     * عرض إعدادات الهوية العامة.
     * يستخدمه نموذج تسجيل الدخول قبل مصادقة المستخدم.
     */
    public function show(): JsonResponse
    {
        $setting = $this->getOrCreateSetting();

        return response()->json([
            'message' => 'تم جلب إعدادات الهوية بنجاح.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * تحديث النصوص والألوان وإعدادات صفحة تسجيل الدخول.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $validated = $request->validate(
            $this->updateValidationRules(),
        );

        $setting = $this->getOrCreateSetting();

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $setting,
                $validated,
            ): void {
                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'updated',
                    summary: $validated['change_summary']
                        ?? 'تم تحديث إعدادات الهوية البصرية.',
                );

                $setting->fill(
                    Arr::except($validated, ['change_summary']),
                );

                // منع تشغيل تسجيل الزوار والتسجيل العام.
                $setting->employee_login_only = true;
                $setting->guest_login_enabled = false;
                $setting->registration_enabled = false;

                $setting->updated_by = $user->id;
                $setting->save();
            });

            return response()->json([
                'message' => 'تم حفظ إعدادات الهوية البصرية بنجاح.',
                'data' => $setting->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر حفظ إعدادات الهوية البصرية.',
            ], 500);
        }
    }

    /**
     * رفع أحد شعارات المنصة.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $validated = $request->validate([
            'logo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,svg',
                'max:5120',
            ],
            'logo_type' => [
                'required',
                Rule::in([
                    'primary',
                    'secondary',
                    'dashboard',
                    'login',
                ]),
            ],
        ]);

        $setting = $this->getOrCreateSetting();

        $column = match ($validated['logo_type']) {
            'primary' => 'primary_logo_path',
            'secondary' => 'secondary_logo_path',
            'dashboard' => 'dashboard_logo_path',
            'login' => 'login_logo_path',
        };

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $setting,
                $validated,
                $column,
            ): void {
                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'logo_uploaded',
                    summary: 'تم رفع أو استبدال شعار المنصة.',
                );

                $this->deletePublicFile($setting->{$column});

                $path = $validated['logo']->store(
                    'branding/logos',
                    'public',
                );

                $setting->{$column} = $path;
                $setting->updated_by = $user->id;
                $setting->save();
            });

            return response()->json([
                'message' => 'تم رفع الشعار بنجاح.',
                'data' => $setting->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر رفع الشعار.',
            ], 500);
        }
    }

    /**
     * حذف أحد شعارات المنصة.
     */
    public function removeLogo(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $validated = $request->validate([
            'logo_type' => [
                'required',
                Rule::in([
                    'primary',
                    'secondary',
                    'dashboard',
                    'login',
                ]),
            ],
        ]);

        $setting = $this->getOrCreateSetting();

        $column = match ($validated['logo_type']) {
            'primary' => 'primary_logo_path',
            'secondary' => 'secondary_logo_path',
            'dashboard' => 'dashboard_logo_path',
            'login' => 'login_logo_path',
        };

        DB::transaction(function () use (
            $request,
            $user,
            $setting,
            $column,
        ): void {
            $this->createVersion(
                setting: $setting,
                user: $user,
                request: $request,
                action: 'logo_removed',
                summary: 'تم حذف أحد شعارات المنصة.',
            );

            $this->deletePublicFile($setting->{$column});

            $setting->{$column} = null;
            $setting->updated_by = $user->id;
            $setting->save();
        });

        return response()->json([
            'message' => 'تم حذف الشعار بنجاح.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * رفع خلفية صفحة تسجيل الدخول.
     */
    public function uploadLoginBackground(
        Request $request,
    ): JsonResponse {
        $user = $this->authorizeBrandingManagement($request);

        $validated = $request->validate([
            'background' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ]);

        $setting = $this->getOrCreateSetting();

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $setting,
                $validated,
            ): void {
                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'background_uploaded',
                    summary: 'تم تغيير خلفية صفحة تسجيل الدخول.',
                );

                $this->deletePublicFile(
                    $setting->login_background_path,
                );

                $path = $validated['background']->store(
                    'branding/login-backgrounds',
                    'public',
                );

                $setting->login_background_path = $path;
                $setting->login_background_type = 'image';
                $setting->updated_by = $user->id;
                $setting->save();
            });

            return response()->json([
                'message' => 'تم رفع خلفية تسجيل الدخول بنجاح.',
                'data' => $setting->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر رفع خلفية تسجيل الدخول.',
            ], 500);
        }
    }

    /**
     * حذف خلفية صفحة تسجيل الدخول.
     */
    public function removeLoginBackground(
        Request $request,
    ): JsonResponse {
        $user = $this->authorizeBrandingManagement($request);

        $setting = $this->getOrCreateSetting();

        DB::transaction(function () use (
            $request,
            $user,
            $setting,
        ): void {
            $this->createVersion(
                setting: $setting,
                user: $user,
                request: $request,
                action: 'background_removed',
                summary: 'تم حذف خلفية صفحة تسجيل الدخول.',
            );

            $this->deletePublicFile(
                $setting->login_background_path,
            );

            $setting->login_background_path = null;
            $setting->login_background_type = 'gradient';
            $setting->updated_by = $user->id;
            $setting->save();
        });

        return response()->json([
            'message' => 'تم حذف الخلفية بنجاح.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * رفع أيقونة الموقع.
     */
    public function uploadFavicon(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $validated = $request->validate([
            'favicon' => [
                'required',
                'file',
                'mimes:ico,png,svg',
                'max:1024',
            ],
        ]);

        $setting = $this->getOrCreateSetting();

        DB::transaction(function () use (
            $request,
            $user,
            $setting,
            $validated,
        ): void {
            $this->createVersion(
                setting: $setting,
                user: $user,
                request: $request,
                action: 'favicon_uploaded',
                summary: 'تم تغيير أيقونة المنصة.',
            );

            $this->deletePublicFile($setting->favicon_path);

            $path = $validated['favicon']->store(
                'branding/favicons',
                'public',
            );

            $setting->favicon_path = $path;
            $setting->updated_by = $user->id;
            $setting->save();
        });

        return response()->json([
            'message' => 'تم رفع أيقونة المنصة بنجاح.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * حذف أيقونة الموقع.
     */
    public function removeFavicon(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $setting = $this->getOrCreateSetting();

        DB::transaction(function () use (
            $request,
            $user,
            $setting,
        ): void {
            $this->createVersion(
                setting: $setting,
                user: $user,
                request: $request,
                action: 'favicon_removed',
                summary: 'تم حذف أيقونة المنصة.',
            );

            $this->deletePublicFile($setting->favicon_path);

            $setting->favicon_path = null;
            $setting->updated_by = $user->id;
            $setting->save();
        });

        return response()->json([
            'message' => 'تم حذف أيقونة المنصة بنجاح.',
            'data' => $setting->fresh(),
        ]);
    }

    /**
     * استعادة الإعدادات الافتراضية.
     */
    public function resetToDefault(Request $request): JsonResponse
    {
        $user = $this->authorizeBrandingManagement($request);

        $setting = $this->getOrCreateSetting();

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $setting,
            ): void {
                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'reset',
                    summary: 'تمت استعادة إعدادات الهوية الافتراضية.',
                );

                $setting->fill($this->defaultSettings());
                $setting->updated_by = $user->id;
                $setting->save();
            });

            return response()->json([
                'message' => 'تمت استعادة الإعدادات الافتراضية.',
                'data' => $setting->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر استعادة الإعدادات الافتراضية.',
            ], 500);
        }
    }

    /**
     * عرض سجل الإصدارات السابقة.
     */
    public function history(Request $request): JsonResponse
    {
        $this->authorizeBrandingManagement($request);

        $setting = $this->getOrCreateSetting();

        $perPage = min(
            max($request->integer('per_page', 15), 1),
            100,
        );

        $versions = $setting->versions()
            ->latest('version_number')
            ->paginate($perPage);

        return response()->json($versions);
    }

    /**
     * استعادة إصدار سابق.
     */
    public function restoreVersion(
        Request $request,
        BrandingSettingVersion $version,
    ): JsonResponse {
        $user = $this->authorizeBrandingManagement($request);

        $setting = $this->getOrCreateSetting();

        if (
            $version->branding_setting_id !== null &&
            $version->branding_setting_id !== $setting->id
        ) {
            return response()->json([
                'message' => 'الإصدار المطلوب لا يتبع إعدادات المنصة الحالية.',
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $request,
                $user,
                $setting,
                $version,
            ): void {
                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'before_restore',
                    summary:
                        "نسخة احتياطية قبل استعادة الإصدار رقم {$version->version_number}.",
                );

                $restorable = Arr::only(
                    $version->settings_snapshot,
                    $setting->getFillable(),
                );

                $setting->fill($restorable);
                $setting->updated_by = $user->id;
                $setting->save();

                $this->createVersion(
                    setting: $setting,
                    user: $user,
                    request: $request,
                    action: 'restored',
                    summary:
                        "تمت استعادة الإصدار رقم {$version->version_number}.",
                );
            });

            return response()->json([
                'message' => 'تمت استعادة الإصدار بنجاح.',
                'data' => $setting->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'تعذر استعادة الإصدار.',
            ], 500);
        }
    }

    /**
     * التحقق من صلاحية المستخدم.
     */
    private function authorizeBrandingManagement(
        Request $request,
    ): User {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user, 401, 'يجب تسجيل الدخول.');

        abort_unless(
            $user->isActive(),
            403,
            'حساب المستخدم غير نشط.',
        );

        abort_unless(
            $user->isApproved(),
            403,
            'حساب المستخدم غير معتمد.',
        );

        abort_unless(
            $user->isPlatformOwner() ||
            $user->hasAnyPermission([
                'branding.manage',
                'branding.update',
                'settings.manage',
                'system.settings.manage',
            ]),
            403,
            'ليس لديك صلاحية إدارة الهوية البصرية.',
        );

        return $user;
    }

    /**
     * إنشاء نسخة من الإعدادات قبل التغيير.
     */
    private function createVersion(
        BrandingSetting $setting,
        User $user,
        Request $request,
        string $action,
        ?string $summary = null,
    ): BrandingSettingVersion {
        $latestVersion = BrandingSettingVersion::query()
            ->where('branding_setting_id', $setting->id)
            ->max('version_number');

        return BrandingSettingVersion::query()->create([
            'branding_setting_id' => $setting->id,
            'version_number' => ((int) $latestVersion) + 1,
            'action' => $action,
            'change_summary' => $summary,
            'settings_snapshot' => Arr::only(
                $setting->getAttributes(),
                $setting->getFillable(),
            ),
            'created_by' => $user->id,
            'creator_name' => $user->displayName('ar'),
            'creator_email' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * جلب السجل الرئيسي أو إنشاؤه تلقائيًا.
     */
    private function getOrCreateSetting(): BrandingSetting
    {
        return BrandingSetting::query()->firstOrCreate(
            ['id' => 1],
            $this->defaultSettings(),
        );
    }

    /**
     * حذف ملف من التخزين العام.
     */
    private function deletePublicFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (
            str_starts_with($path, 'http://') ||
            str_starts_with($path, 'https://') ||
            str_starts_with($path, '/')
        ) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * قواعد التحقق من بيانات التحديث.
     */
    private function updateValidationRules(): array
    {
        $hexColor = ['regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'platform_name_ar' => ['sometimes', 'string', 'max:150'],
            'platform_name_en' => ['sometimes', 'string', 'max:150'],

            'company_name_ar' => ['nullable', 'string', 'max:200'],
            'company_name_en' => ['nullable', 'string', 'max:200'],

            'platform_description_ar' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'platform_description_en' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'copyright_ar' => ['nullable', 'string', 'max:500'],
            'copyright_en' => ['nullable', 'string', 'max:500'],

            'login_title_ar' => ['sometimes', 'string', 'max:200'],
            'login_title_en' => ['sometimes', 'string', 'max:200'],

            'login_description_ar' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'login_description_en' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'login_form_title_ar' => [
                'sometimes',
                'string',
                'max:200',
            ],
            'login_form_title_en' => [
                'sometimes',
                'string',
                'max:200',
            ],

            'login_form_description_ar' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'login_form_description_en' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'login_button_text_ar' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'login_button_text_en' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'email_label_ar' => ['sometimes', 'string', 'max:100'],
            'email_label_en' => ['sometimes', 'string', 'max:100'],

            'password_label_ar' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'password_label_en' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'remember_me_text_ar' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'remember_me_text_en' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'forgot_password_text_ar' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'forgot_password_text_en' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'login_background_type' => [
                'sometimes',
                Rule::in(['color', 'gradient', 'image']),
            ],

            'login_background_position' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'login_background_size' => [
                'sometimes',
                Rule::in(['cover', 'contain', 'auto']),
            ],

            'login_overlay_opacity' => [
                'sometimes',
                'numeric',
                'min:0',
                'max:1',
            ],

            'show_login_logo' => ['sometimes', 'boolean'],
            'show_login_side_panel' => ['sometimes', 'boolean'],
            'show_remember_me' => ['sometimes', 'boolean'],
            'show_forgot_password' => ['sometimes', 'boolean'],
            'show_language_switcher' => ['sometimes', 'boolean'],

            'employee_login_only' => ['sometimes', 'boolean'],

            'primary_color' => ['sometimes', ...$hexColor],
            'secondary_color' => ['sometimes', ...$hexColor],

            'login_button_color' => ['sometimes', ...$hexColor],
            'login_button_text_color' => ['sometimes', ...$hexColor],

            'login_page_background_color' => [
                'sometimes',
                ...$hexColor,
            ],
            'login_card_background_color' => [
                'sometimes',
                ...$hexColor,
            ],
            'login_heading_color' => ['sometimes', ...$hexColor],
            'login_text_color' => ['sometimes', ...$hexColor],
            'login_input_border_color' => [
                'sometimes',
                ...$hexColor,
            ],
            'login_side_start_color' => [
                'sometimes',
                ...$hexColor,
            ],
            'login_side_end_color' => [
                'sometimes',
                ...$hexColor,
            ],

            'font_family_ar' => [
                'sometimes',
                'string',
                'max:100',
            ],
            'font_family_en' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'login_card_radius' => [
                'sometimes',
                'integer',
                'min:0',
                'max:80',
            ],

            'login_button_radius' => [
                'sometimes',
                'integer',
                'min:0',
                'max:80',
            ],

            'login_card_shadow' => [
                'sometimes',
                'string',
                'max:300',
            ],

            'default_locale' => [
                'sometimes',
                Rule::in(['ar', 'en']),
            ],

            'arabic_enabled' => ['sometimes', 'boolean'],
            'english_enabled' => ['sometimes', 'boolean'],

            'seo_title_ar' => ['nullable', 'string', 'max:200'],
            'seo_title_en' => ['nullable', 'string', 'max:200'],

            'seo_description_ar' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'seo_description_en' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'custom_settings' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],

            'change_summary' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * القيم الافتراضية للتصميم المعتمد.
     */
    private function defaultSettings(): array
    {
        return [
            'platform_name_ar' => 'زاد',
            'platform_name_en' => 'ZAD',

            'company_name_ar' => 'منصة زاد للأسر المنتجة',
            'company_name_en' =>
                'ZAD Productive Families Platform',

            'platform_description_ar' =>
                'منصة احترافية متكاملة لإدارة أعمال زاد بكفاءة وموثوقية.',

            'platform_description_en' =>
                'A professional integrated platform for managing ZAD operations efficiently and reliably.',

            'copyright_ar' =>
                'جميع الحقوق محفوظة © زاد ' . now()->year,

            'copyright_en' =>
                'All rights reserved © ZAD ' . now()->year,

            'login_title_ar' =>
                'مرحبًا بك في لوحة تحكم زاد',

            'login_title_en' =>
                'Welcome to ZAD Control Center',

            'login_description_ar' =>
                'منصة الأسر المنتجة',

            'login_description_en' =>
                'Productive Families Platform',

            'login_form_title_ar' => 'تسجيل الدخول',
            'login_form_title_en' => 'Sign In',

            'login_form_description_ar' =>
                'يرجى إدخال بيانات الموظف للوصول إلى لوحة التحكم.',

            'login_form_description_en' =>
                'Enter your employee credentials to access the dashboard.',

            'login_button_text_ar' => 'تسجيل الدخول',
            'login_button_text_en' => 'Sign In',

            'email_label_ar' => 'البريد الإلكتروني',
            'email_label_en' => 'Email Address',

            'password_label_ar' => 'كلمة المرور',
            'password_label_en' => 'Password',

            'remember_me_text_ar' => 'تذكرني',
            'remember_me_text_en' => 'Remember Me',

            'forgot_password_text_ar' =>
                'نسيت كلمة المرور؟',

            'forgot_password_text_en' =>
                'Forgot Password?',

            'login_background_type' => 'gradient',
            'login_background_position' => 'center',
            'login_background_size' => 'cover',
            'login_overlay_opacity' => 0,

            'show_login_logo' => true,
            'show_login_side_panel' => true,
            'show_remember_me' => true,
            'show_forgot_password' => true,
            'show_language_switcher' => true,

            'employee_login_only' => true,
            'guest_login_enabled' => false,
            'registration_enabled' => false,

            'primary_color' => '#F97316',
            'secondary_color' => '#17365D',

            'login_button_color' => '#F97316',
            'login_button_text_color' => '#FFFFFF',

            'login_page_background_color' => '#FFF7F0',
            'login_card_background_color' => '#FFFFFF',

            'login_heading_color' => '#17365D',
            'login_text_color' => '#64748B',

            'login_input_border_color' => '#DCE3EC',

            'login_side_start_color' => '#FFD8BF',
            'login_side_end_color' => '#F8B88A',

            'font_family_ar' => 'Cairo',
            'font_family_en' => 'Inter',

            'login_card_radius' => 28,
            'login_button_radius' => 12,

            'login_card_shadow' =>
                '0 24px 60px rgba(15, 23, 42, 0.12)',

            'default_locale' => 'ar',
            'arabic_enabled' => true,
            'english_enabled' => true,

            'seo_title_ar' => 'لوحة تحكم زاد',
            'seo_title_en' => 'ZAD Admin Dashboard',

            'seo_description_ar' =>
                'لوحة التحكم الإدارية لمنصة زاد للأسر المنتجة.',

            'seo_description_en' =>
                'Administrative dashboard for the ZAD productive families platform.',

            'custom_settings' => [],
            'is_active' => true,
        ];
    }
}