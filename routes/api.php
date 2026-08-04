<?php

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
        Route::put('/{family}', [ProductiveFamilyController::class, 'update']);
        Route::patch('/{family}/status', [ProductiveFamilyController::class, 'changeStatus']);
        Route::delete('/{family}', [ProductiveFamilyController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | Branding Management
    |--------------------------------------------------------------------------
    |
    | إدارة هوية المنصة وصفحة تسجيل الدخول.
    | التحقق من صلاحية المستخدم سيتم أيضًا داخل Controller.
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
    |
    | المسارات التالية هي طبقة الربط الموحدة لكل صفحات لوحة React. تحفظ
    | السجلات الديناميكية في platform_records وتدعم CRUD والحالات والتصدير.
    |
    */

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminResourceController::class, 'dashboard']);
        Route::get('/dashboard/export', [AdminResourceController::class, 'dashboardExport']);

        Route::get('/settings', [AdminResourceController::class, 'settings']);
        Route::put('/settings', [AdminResourceController::class, 'saveSettings']);
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