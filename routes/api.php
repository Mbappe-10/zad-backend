<?php

use App\Http\Controllers\Api\Admin\OrderJourneyAdminController;
use App\Http\Controllers\Api\App\OrderLiveController;
use App\Http\Controllers\Api\App\ProductiveFamilyProfileController;
use App\Http\Controllers\Api\App\SocialAuthController;
use App\Http\Controllers\Api\App\FamilyOrderController;
use App\Http\Controllers\Api\App\DriverOrderController;
use App\Http\Controllers\Api\App\DriverProfileController;
use App\Http\Controllers\Api\App\AppOrderController;
use App\Http\Controllers\Api\App\PhoneVerificationController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\DriverProfileFieldController;
use App\Http\Controllers\Api\Admin\ProductFieldSettingController;
use App\Http\Controllers\Api\Admin\ProductImageController;
use App\Http\Controllers\Api\App\StoreCatalogController;
use App\Http\Controllers\Api\App\BootstrapController;
use App\Http\Controllers\Api\App\GuestSessionController;
use App\Http\Controllers\Api\Admin\StoreController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingSettingController;
use App\Http\Controllers\Api\PlatformRecordController;
use App\Http\Controllers\Api\DigitalEmployeeController;
use App\Http\Controllers\Api\ApprovalRequestController;
use App\Http\Controllers\Api\SystemDictionaryController;
use App\Http\Controllers\Api\CoreResourceController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\DeliveryOperationsController;
use App\Http\Controllers\Api\WorkforceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Admin\ProductiveFamilyController;
use App\Http\Controllers\Api\Admin\LiveBroadcastAdminController;
use App\Http\Controllers\Api\AdminResourceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| مسارات عامة لا تحتاج إلى تسجيل الدخول.
| صفحة تسجيل الدخول تستخدم هذا المسار لجلب الهوية البصرية.
|
*/

Route::get('/branding', [BrandingSettingController::class, 'show'])
    ->name('api.branding.show');

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
|
| جميع المسارات الموجودة داخل هذه المجموعة تحتاج إلى مستخدم
| مسجل دخوله عن طريق Laravel Sanctum.
|
*/
/*
|--------------------------------------------------------------------------
| Authentication - Bearer Token
|--------------------------------------------------------------------------
|
| هذا المسار عام ولا يستخدم CSRF أو جلسات المتصفح.
| الواجهة تستقبل Sanctum Bearer Token وتُرسله في Authorization header.
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.auth.login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Current Authenticated User
    |--------------------------------------------------------------------------
    */
     Route::get(
        '/delivery/orders/{order}/journey',
        [OrderJourneyAdminController::class, 'journey'],
    );

    Route::get(
        '/admin/journey-retention-settings',
        [OrderJourneyAdminController::class, 'settings'],
    );

    Route::put(
        '/admin/journey-retention-settings',
        [OrderJourneyAdminController::class, 'updateSettings'],
    );

    Route::post(
        '/delivery/orders/{order}/retention/hold',
        [OrderJourneyAdminController::class, 'hold'],
    );

    Route::post(
        '/delivery/orders/{order}/retention/release',
        [OrderJourneyAdminController::class, 'release'],
    );

    Route::post(
        '/delivery/orders/{order}/retention/extend',
        [OrderJourneyAdminController::class, 'extend'],
    );

    Route::post(
        '/delivery/orders/{order}/retention/purge',
        [OrderJourneyAdminController::class, 'purge'],
    );

    Route::post(
        '/delivery/orders/retention/bulk',
        [OrderJourneyAdminController::class, 'bulk'],
    );
Route::prefix('v1/app/family')->group(function (): void {
    Route::get('/orders', [FamilyOrderController::class, 'index']);
    Route::get('/orders/{order}', [FamilyOrderController::class, 'show']);
    Route::post('/orders/{order}/transition', [FamilyOrderController::class, 'transition']);

    Route::post(
        '/orders/{order}/live/start',
        [OrderLiveController::class, 'start'],
    );

    Route::post(
        '/orders/{order}/live/pause',
        [OrderLiveController::class, 'pause'],
    );

    Route::post(
        '/orders/{order}/live/resume',
        [OrderLiveController::class, 'resume'],
    );

    Route::post(
        '/orders/{order}/live/finish',
        [OrderLiveController::class, 'finish'],
    );
});

Route::prefix('v1/app/driver')->group(function (): void {
    Route::get('/profile', [DriverProfileController::class, 'show']);

    Route::post(
        '/profile',
        [DriverProfileController::class, 'store'],
    )->middleware('throttle:5,1');

    Route::get(
        '/profile-fields',
        [DriverProfileController::class, 'fields'],
    );

    Route::get('/orders', [DriverOrderController::class, 'index']);
    Route::get('/orders/{order}', [DriverOrderController::class, 'show']);
});


    Route::get('/me', [AuthController::class, 'me'])
        ->name('api.auth.me');

    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->name('api.auth.logout');

    Route::post('/auth/logout-all', [AuthController::class, 'logoutAll'])
        ->name('api.auth.logout-all');

    Route::put('/profile', [AuthController::class, 'updateProfile'])
        ->name('api.profile.update');

    Route::put('/profile/password', [AuthController::class, 'changePassword'])
        ->name('api.profile.password');

    Route::post('/profile/photo', [AuthController::class, 'uploadProfilePhoto'])
        ->name('api.profile.photo.store');

    Route::delete('/profile/photo', [AuthController::class, 'removeProfilePhoto'])
        ->name('api.profile.photo.destroy');

    Route::get('/system/dictionaries', [SystemDictionaryController::class, 'index']);

    Route::prefix('delivery')->group(function () {
        Route::get('/dashboard', [DeliveryOperationsController::class, 'dashboard']);
        Route::get('/drivers/available', [DeliveryOperationsController::class, 'availableDrivers']);
        Route::post('/drivers/{driver}/location', [DeliveryOperationsController::class, 'updateDriverLocation']);
        Route::post('/orders/{order}/transition', [DeliveryOperationsController::class, 'transition']);
        Route::post('/orders/{order}/assign', [DeliveryOperationsController::class, 'assign']);
        Route::post('/orders/{order}/auto-assign', [DeliveryOperationsController::class, 'autoAssign']);
        Route::get('/orders/{order}/timeline', [DeliveryOperationsController::class, 'timeline']);
        Route::get('/orders/{order}/assignments', [DeliveryOperationsController::class, 'assignments']);
        Route::post('/quote', [DeliveryOperationsController::class, 'quote']);
    });

    Route::prefix('workforce')->group(function () {
        Route::get('/dashboard', [WorkforceController::class, 'dashboard']);
        Route::get('/employees', [WorkforceController::class, 'employees']);
        Route::post('/employees', [WorkforceController::class, 'storeEmployee']);
        Route::put('/employees/{employee}', [WorkforceController::class, 'updateEmployee']);
        Route::delete('/employees/{employee}', [WorkforceController::class, 'destroyEmployee']);
        Route::get('/tasks', [WorkforceController::class, 'tasks']);
        Route::get('/tasks/{task}/timeline', [WorkforceController::class, 'taskTimeline']);
        Route::post('/tasks/{task}/cancel', [WorkforceController::class, 'cancelTask']);
        Route::post('/tasks/{task}/retry', [WorkforceController::class, 'retryTask']);
        Route::get('/rules', [WorkforceController::class, 'rules']);
        Route::post('/rules/{rule}/run', [WorkforceController::class, 'runRule']);
        Route::get('/events', [WorkforceController::class, 'events']);
    });

    Route::prefix('reports')->group(function () {
        Route::get('/catalog', [ReportController::class, 'catalog']);
        Route::get('/dashboard', [ReportController::class, 'dashboard']);
        Route::get('/{report}', [ReportController::class, 'data']);
        Route::get('/{report}/export', [ReportController::class, 'export']);
    });

    Route::prefix('finance')->group(function () {
        Route::get('/summary', [FinanceController::class, 'summary']);
        Route::get('/providers', [FinanceController::class, 'providers']);
        Route::post('/providers', [FinanceController::class, 'storeProvider']);
        Route::put('/providers/{provider}', [FinanceController::class, 'updateProvider']);
        Route::get('/payments', [FinanceController::class, 'payments']);
        Route::post('/payments', [FinanceController::class, 'storePayment']);
        Route::post('/payments/{payment}/refund', [FinanceController::class, 'refund']);
        Route::get('/commission-rules', [FinanceController::class, 'commissionRules']);
        Route::post('/commission-rules', [FinanceController::class, 'storeCommissionRule']);
        Route::put('/commission-rules/{rule}', [FinanceController::class, 'updateCommissionRule']);
        Route::get('/wallets', [FinanceController::class, 'wallets']);
        Route::post('/wallets/{wallet}/credit', [FinanceController::class, 'creditWallet']);
        Route::post('/wallets/{wallet}/freeze', [FinanceController::class, 'freezeWallet']);
        Route::get('/transactions', [FinanceController::class, 'walletTransactions']);
        Route::get('/payouts', [FinanceController::class, 'payouts']);
        Route::post('/wallets/{wallet}/payouts', [FinanceController::class, 'requestPayout']);
        Route::post('/payouts/{payout}/decision', [FinanceController::class, 'decidePayout']);
        Route::get('/ledger', [FinanceController::class, 'ledger']);
    });
     
    Route::prefix('admin/drivers')->group(function () {
        Route::get('/stats', [DriverController::class, 'stats']);
        Route::get('/export', [DriverController::class, 'export']);

        Route::get('/', [DriverController::class, 'index']);
        Route::post('/', [DriverController::class, 'store']);

    Route::match(
        ['put', 'patch'],
        '/{driver}',
        [DriverController::class, 'update'],
    );

    Route::patch(
        '/{driver}/status',
        [DriverController::class, 'changeStatus'],
    );

    Route::delete(
        '/{driver}',
        [DriverController::class, 'destroy'],
        );
    });

    Route::prefix('core')->group(function () {
        Route::post('/{resource}/bulk', [CoreResourceController::class, 'bulk']);
        Route::post('/{resource}/upload', [CoreResourceController::class, 'upload']);
        Route::get('/{resource}', [CoreResourceController::class, 'index']);
        Route::post('/{resource}', [CoreResourceController::class, 'store']);
        Route::get('/{resource}/{id}', [CoreResourceController::class, 'show'])->whereNumber('id');
        Route::match(['put', 'patch'], '/{resource}/{id}', [CoreResourceController::class, 'update'])->whereNumber('id');
        Route::delete('/{resource}/{id}', [CoreResourceController::class, 'destroy'])->whereNumber('id');
    });

    Route::get('/approvals', [ApprovalRequestController::class, 'index'])->middleware('permission:governance.view');
    Route::post('/approvals', [ApprovalRequestController::class, 'store']);
    Route::post('/approvals/{approvalRequest}/decision', [ApprovalRequestController::class, 'decide'])->middleware('permission:governance.approve');

    Route::post('/platform/{resource}/bulk', [PlatformRecordController::class, 'bulk']);
    Route::apiResource('/platform/{resource}', PlatformRecordController::class)->parameters(['{resource}' => 'record']);

    Route::get('/digital-employees', [DigitalEmployeeController::class, 'index']);
    Route::post('/digital-employees', [DigitalEmployeeController::class, 'store']);
    Route::get('/digital-employees/{digitalEmployee}', [DigitalEmployeeController::class, 'show']);
    Route::put('/digital-employees/{digitalEmployee}', [DigitalEmployeeController::class, 'update']);
    Route::delete('/digital-employees/{digitalEmployee}', [DigitalEmployeeController::class, 'destroy']);
    Route::post('/digital-employees/{digitalEmployee}/tasks', [DigitalEmployeeController::class, 'addTask']);
    Route::post('/digital-employee-tasks/{task}/run', [DigitalEmployeeController::class, 'runTask']);
    Route::post('/digital-employee-tasks/{task}/approval', [DigitalEmployeeController::class, 'approveTask']);
    Route::post('/digital-employees/{digitalEmployee}/rules', [DigitalEmployeeController::class, 'addRule']);
    Route::post('/automation-rules/{rule}/toggle', [DigitalEmployeeController::class, 'toggleRule']);

    /*
    |--------------------------------------------------------------------------
    | Product Image Upload
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/admin/products/{product}/image',
        [ProductImageController::class, 'store'],
    )->name('api.admin.products.image.store');

    Route::delete(
        '/admin/products/{product}/image',
        [ProductImageController::class, 'destroy'],
    )->name('api.admin.products.image.destroy');


    /*
    |--------------------------------------------------------------------------
    | Product Field Settings
    |--------------------------------------------------------------------------
    |
    | إدارة الحقول متاحة فقط للمستخدم المصادق عليه، ويمكن لاحقًا
    | ربطها بصلاحية products.fields.manage أو products.fields.view.
    |
    */

    Route::get(
        '/admin/product-fields',
        [ProductFieldSettingController::class, 'index'],
    )->name('api.admin.product-fields.index');

    Route::put(
        '/admin/product-fields',
        [ProductFieldSettingController::class, 'update'],
    )
        ->middleware('permission:products.fields.manage')
        ->name('api.admin.product-fields.update');

    /*
    |--------------------------------------------------------------------------
    | Productive Families Management
    |--------------------------------------------------------------------------
    |
    | مسارات إدارة الأسر المنتجة المتوافقة مع صفحة React.
    |
    */

    Route::prefix('admin/families')->group(function () {
        Route::get('/stats', [ProductiveFamilyController::class, 'stats']);
        Route::get('/export', [ProductiveFamilyController::class, 'export']);

        Route::get('/', [ProductiveFamilyController::class, 'index']);
        Route::post('/', [ProductiveFamilyController::class, 'store']);
        Route::match(
            ['put', 'patch'],
            '/{family}',
            [ProductiveFamilyController::class, 'update'],
        );

        Route::patch(
            '/{family}/status',
            [ProductiveFamilyController::class, 'changeStatus'],
        );

        Route::delete(
            '/{family}',
            [ProductiveFamilyController::class, 'destroy'],
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Stores Management
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin/stores')->group(function () {
        Route::get('/', [StoreController::class, 'index']);
        Route::post('/', [StoreController::class, 'store']);

        Route::get('/{store}', [StoreController::class, 'show']);

        Route::match(
            ['put', 'patch'],
            '/{store}',
            [StoreController::class, 'update'],
        );

        Route::patch(
            '/{store}/status',
            [StoreController::class, 'changeStatus'],
        );

        Route::patch(
            '/{store}/open-status',
            [StoreController::class, 'updateOpenStatus'],
        );

        Route::delete(
            '/{store}',
            [StoreController::class, 'destroy'],
        );
    });

    /*
    |--------------------------------------------------------------------------
    | Branding Management
    |--------------------------------------------------------------------------
    |
    | إدارة هوية المنصة وصفحة تسجيل الدخول.
    |
    */

    Route::prefix('branding')
        ->name('api.branding.')
        ->group(function () {
            Route::put('/', [BrandingSettingController::class, 'update'])
                ->name('update');

            Route::post('/logo', [BrandingSettingController::class, 'uploadLogo'])
                ->name('logo.upload');

            Route::delete('/logo', [BrandingSettingController::class, 'removeLogo'])
                ->name('logo.remove');

            Route::post('/login-background', [
                BrandingSettingController::class,
                'uploadLoginBackground',
            ])->name('login-background.upload');

            Route::delete('/login-background', [
                BrandingSettingController::class,
                'removeLoginBackground',
            ])->name('login-background.remove');

            Route::post('/favicon', [
                BrandingSettingController::class,
                'uploadFavicon',
            ])->name('favicon.upload');

            Route::delete('/favicon', [
                BrandingSettingController::class,
                'removeFavicon',
            ])->name('favicon.remove');

            Route::post('/reset', [
                BrandingSettingController::class,
                'resetToDefault',
            ])->name('reset');

            Route::get('/history', [
                BrandingSettingController::class,
                'history',
            ])->name('history');

            Route::post('/history/{version}/restore', [
                BrandingSettingController::class,
                'restoreVersion',
            ])->name('history.restore');
        });

    /*
    |--------------------------------------------------------------------------
    | Admin Dashboard Compatibility API
    |--------------------------------------------------------------------------
    */

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminResourceController::class, 'dashboard']);
        Route::get('/dashboard/export', [AdminResourceController::class, 'dashboardExport']);

        Route::get('/settings', [AdminResourceController::class, 'settings']);
        Route::put('/settings', [AdminResourceController::class, 'saveSettings']);

        /*
        |--------------------------------------------------------------------------
        | Driver Registration Field Settings
        |--------------------------------------------------------------------------
        |
        | إدارة الأسئلة الإضافية في نموذج المندوب. يجب أن تبقى هذه المسارات
        | قبل مسارات /{resource} الديناميكية الموجودة في أسفل المجموعة.
        |
        */

        Route::prefix('driver-profile-fields')->group(function (): void {
            Route::get(
                '/',
                [DriverProfileFieldController::class, 'index'],
            )->name('api.admin.driver-profile-fields.index');

            Route::post(
                '/',
                [DriverProfileFieldController::class, 'store'],
            )->name('api.admin.driver-profile-fields.store');

            Route::match(
                ['put', 'patch'],
                '/{field}',
                [DriverProfileFieldController::class, 'update'],
            )->name('api.admin.driver-profile-fields.update');

            Route::delete(
                '/{field}',
                [DriverProfileFieldController::class, 'destroy'],
            )->name('api.admin.driver-profile-fields.destroy');
        });

        /*
        |--------------------------------------------------------------------------
        | Live Broadcast Owner Control
        |--------------------------------------------------------------------------
        |
        | هذه المسارات محمية داخل LiveBroadcastAdminController، ولا يسمح
        | بتنفيذها إلا لمالك المنصة. يجب أن تبقى قبل مسارات {resource}
        | الديناميكية الموجودة في أسفل هذه المجموعة.
        |
        */

        Route::get(
            '/live-broadcasts',
            [LiveBroadcastAdminController::class, 'index'],
        )->name('api.admin.live-broadcasts.index');

        Route::post(
            '/live-broadcasts/{session}/extend',
            [LiveBroadcastAdminController::class, 'extend'],
        )->name('api.admin.live-broadcasts.extend');

        Route::post(
            '/live-broadcasts/{session}/end',
            [LiveBroadcastAdminController::class, 'end'],
        )->name('api.admin.live-broadcasts.end');

        Route::put(
            '/live-broadcast-settings',
            [LiveBroadcastAdminController::class, 'updateSettings'],
        )->name('api.admin.live-broadcast-settings.update');

        /*
        |--------------------------------------------------------------------------
        | Governed Platform Settings
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/platform-settings/meta',
            [PlatformSettingsController::class, 'meta'],
        )->name('api.admin.platform-settings.meta');

        Route::get(
            '/platform-settings/audits',
            [PlatformSettingsController::class, 'audits'],
        )->name('api.admin.platform-settings.audits');

        Route::post(
            '/platform-settings/audits/{audit}/rollback',
            [PlatformSettingsController::class, 'rollback'],
        )->name('api.admin.platform-settings.rollback');

        Route::get(
            '/platform-settings',
            [PlatformSettingsController::class, 'index'],
        )->name('api.admin.platform-settings.index');

        Route::put(
            '/platform-settings',
            [PlatformSettingsController::class, 'update'],
        )->name('api.admin.platform-settings.update');

        Route::get('/{resource}/tickets', [AdminResourceController::class, 'index'])
            ->where('resource', 'support');
        Route::post('/{resource}/tickets', [AdminResourceController::class, 'store'])
            ->where('resource', 'support');
        Route::get('/{resource}/tickets/{record}', [AdminResourceController::class, 'show'])
            ->where('resource', 'support')
            ->whereNumber('record');
        Route::match(['put', 'patch'], '/{resource}/tickets/{record}', [AdminResourceController::class, 'update'])
            ->where('resource', 'support')
            ->whereNumber('record');
        Route::delete('/{resource}/tickets/{record}', [AdminResourceController::class, 'destroy'])
            ->where('resource', 'support')
            ->whereNumber('record');
        Route::match(['post', 'patch'], '/{resource}/tickets/{record}/{action}', [AdminResourceController::class, 'action'])
            ->where('resource', 'support')
            ->whereNumber('record');

        Route::get('/{resource}/stats', [AdminResourceController::class, 'stats']);
        Route::get('/{resource}/export', [AdminResourceController::class, 'export']);
        Route::post('/{resource}/upload', [AdminResourceController::class, 'upload']);
        Route::match(['get', 'post'], '/{resource}/{action}', [AdminResourceController::class, 'collectionAction'])
            ->whereIn('action', [
                'forecast',
                'insights',
                'refresh',
                'simulate',
            ]);

        Route::get('/{resource}', [AdminResourceController::class, 'index']);
        Route::post('/{resource}', [AdminResourceController::class, 'store']);
        Route::get('/{resource}/{record}', [AdminResourceController::class, 'show'])->whereNumber('record');
        Route::match(['put', 'patch'], '/{resource}/{record}', [AdminResourceController::class, 'update'])->whereNumber('record');
        Route::delete('/{resource}/{record}', [AdminResourceController::class, 'destroy'])->whereNumber('record');
        Route::get('/{resource}/{record}/{action}', [AdminResourceController::class, 'action'])
            ->whereNumber('record')
            ->whereIn('action', [
                'analytics',
                'audit',
                'invoices',
                'payments',
                'transactions',
                'usage',
            ]);
        Route::match(['post', 'patch'], '/{resource}/{record}/{action}', [AdminResourceController::class, 'action'])
            ->whereNumber('record');
    });
});

Route::prefix('v1/app')->group(function (): void {
    Route::get('/bootstrap', BootstrapController::class);

    Route::post(
        '/auth/google',
        [SocialAuthController::class, 'google'],
    )->middleware('throttle:10,1');

    Route::post(
        '/phone-verifications/send',
        [PhoneVerificationController::class, 'send'],
    );

    Route::post(
        '/phone-verifications/verify',
        [PhoneVerificationController::class, 'verify'],
    );

    Route::get(
        '/orders',
        [AppOrderController::class, 'index'],
    );

    Route::post(
        '/orders',
        [AppOrderController::class, 'store'],
    );

    Route::get(
        '/orders/{order}',
        [AppOrderController::class, 'show'],
    );

    Route::get(
        '/product-fields',
        [ProductFieldSettingController::class, 'familyFields'],
    )->name('api.app.product-fields.index');

    Route::get(
        '/stores/{store}/products',
        [StoreCatalogController::class, 'show'],
    );

    Route::post(
        '/guest-sessions',
        [GuestSessionController::class, 'store'],
    );

    Route::patch(
        '/guest-sessions/{guest}',
        [GuestSessionController::class, 'update'],
    );

    Route::get(
        '/orders/{order}/live',
        [OrderLiveController::class, 'status'],
    )->middleware('throttle:30,1');

    Route::post(
        '/orders/{order}/live/viewer-token',
        [OrderLiveController::class, 'viewerToken'],
    )->middleware('throttle:20,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get(
            '/productive-family/profile',
            [ProductiveFamilyProfileController::class, 'show'],
        );

        Route::post(
            '/productive-family/profile',
            [ProductiveFamilyProfileController::class, 'store'],
        )->middleware('throttle:10,1');
    });
});