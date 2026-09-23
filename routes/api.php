<?php

use App\Http\Controllers\Api\Admin\OrderChatAdminController;
use App\Http\Controllers\Api\App\OrderChatController;
use App\Http\Controllers\Api\Admin\DriverProfileFieldController;
use App\Http\Controllers\Api\Admin\DriverController;
use App\Http\Controllers\Api\Admin\LiveBroadcastAdminController;
use App\Http\Controllers\Api\Admin\OrderJourneyAdminController;
use App\Http\Controllers\Api\Admin\ProductFieldSettingController;
use App\Http\Controllers\Api\Admin\ProductImageController;
use App\Http\Controllers\Api\Admin\ProductiveFamilyController;
use App\Http\Controllers\Api\Admin\RolePortalAdminController;
use App\Http\Controllers\Api\Admin\ControlCenterController;
use App\Http\Controllers\Api\Admin\PayoutAdminController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\PlatformSettingsController;
use App\Http\Controllers\Api\Admin\PolicyCenterController;
use App\Http\Controllers\Api\App\PublicPolicyController;
use App\Http\Controllers\Api\Admin\StoreController;
use App\Http\Controllers\Api\AdminResourceController;
use App\Http\Controllers\Api\App\AppOrderController;
use App\Http\Controllers\Api\App\BootstrapController;
use App\Http\Controllers\Api\App\PublicMediaController;
use App\Http\Controllers\Api\App\DriverOrderController;
use App\Http\Controllers\Api\App\DriverProfileController;
use App\Http\Controllers\Api\App\FamilyOrderController;
use App\Http\Controllers\Api\App\FamilyProductController;
use App\Http\Controllers\Api\App\GuestSessionController;
use App\Http\Controllers\Api\App\OrderLiveController;
use App\Http\Controllers\Api\App\PhoneVerificationController;
use App\Http\Controllers\Api\App\PromotionTrackingController;
use App\Http\Controllers\Api\App\ProductiveFamilyProfileController;
use App\Http\Controllers\Api\App\RoleDashboardController;
use App\Http\Controllers\Api\App\RolePortalController;
use App\Http\Controllers\Api\App\SocialAuthController;
use App\Http\Controllers\Api\App\StoreCatalogController;
use App\Http\Controllers\Api\ApprovalRequestController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandingSettingController;
use App\Http\Controllers\Api\CoreResourceController;
use App\Http\Controllers\Api\DeliveryOperationsController;
use App\Http\Controllers\Api\DigitalEmployeeController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\PlatformRecordController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SystemDictionaryController;
use App\Http\Controllers\Api\WorkforceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API Routes
|--------------------------------------------------------------------------
|
| ظ…ط³ط§ط±ط§طھ ط¹ط§ظ…ط© ظ„ط§ طھط­طھط§ط¬ ط¥ظ„ظ‰ طھط³ط¬ظٹظ„ ط§ظ„ط¯ط®ظˆظ„.
| طµظپط­ط© طھط³ط¬ظٹظ„ ط§ظ„ط¯ط®ظˆظ„ طھط³طھط®ط¯ظ… ظ‡ط°ط§ ط§ظ„ظ…ط³ط§ط± ظ„ط¬ظ„ط¨ ط§ظ„ظ‡ظˆظٹط© ط§ظ„ط¨طµط±ظٹط©.
|
*/

Route::get('/branding', [BrandingSettingController::class, 'show'])
    ->name('api.branding.show');

/* ZAD_PUBLIC_MEDIA_CORS_V1 */
Route::get('/v1/app/media', [PublicMediaController::class, 'show'])
    ->middleware('throttle:300,1')
    ->name('api.app.media.show');

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
|
| ط¬ظ…ظٹط¹ ط§ظ„ظ…ط³ط§ط±ط§طھ ط§ظ„ظ…ظˆط¬ظˆط¯ط© ط¯ط§ط®ظ„ ظ‡ط°ظ‡ ط§ظ„ظ…ط¬ظ…ظˆط¹ط© طھط­طھط§ط¬ ط¥ظ„ظ‰ ظ…ط³طھط®ط¯ظ…
| ظ…ط³ط¬ظ„ ط¯ط®ظˆظ„ظ‡ ط¹ظ† ط·ط±ظٹظ‚ Laravel Sanctum.
|
*/
/*
|--------------------------------------------------------------------------
| Authentication - Bearer Token
|--------------------------------------------------------------------------
|
| ظ‡ط°ط§ ط§ظ„ظ…ط³ط§ط± ط¹ط§ظ… ظˆظ„ط§ ظٹط³طھط®ط¯ظ… CSRF ط£ظˆ ط¬ظ„ط³ط§طھ ط§ظ„ظ…طھطµظپط­.
| ط§ظ„ظˆط§ط¬ظ‡ط© طھط³طھظ‚ط¨ظ„ Sanctum Bearer Token ظˆطھظڈط±ط³ظ„ظ‡ ظپظٹ Authorization header.
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('api.auth.login');

Route::get('/v1/app/policies', [PublicPolicyController::class, 'index'])
    ->name('api.app.policies.index');

Route::get('/v1/app/policies/{key}', [PublicPolicyController::class, 'show'])
    ->where('key', '[a-z_]+')
    ->name('api.app.policies.show');

// ZAD_TRACKING_MEDIA_STREAM_V1
Route::get(
    '/v1/app/order-tracking/proofs/{proof}/media',
    [AppOrderController::class, 'trackingProofMedia'],
)
    ->middleware(['signed:relative', 'throttle:120,1'])
    ->name('api.app.order-tracking.proof-media');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::get('/admin/control-center', [ControlCenterController::class, 'index']);
    Route::patch('/admin/control-center/{section}', [ControlCenterController::class, 'update']);
    Route::post('/admin/control-center/actions/{action}', [ControlCenterController::class, 'action']);

    /*
    |--------------------------------------------------------------------------
    | Current Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/v1/app/driver/dashboard',
        [RoleDashboardController::class, 'driver'],
    );

    Route::get(
        '/v1/app/family/dashboard',
        [RoleDashboardController::class, 'family'],
    );

    Route::patch(
        '/v1/app/family/availability',
        [RoleDashboardController::class, 'familyAvailability'],
    )->middleware('throttle:20,1');

    Route::get(
        '/v1/app/{role}/portal/{module}',
        [RolePortalController::class, 'show'],
    )->whereIn('role', ['family', 'driver']);

    Route::post(
        '/v1/app/{role}/portal/{module}',
        [RolePortalController::class, 'store'],
    )
        ->whereIn('role', ['family', 'driver'])
        ->middleware('throttle:10,1');

    Route::prefix('admin/role-portal')->group(function (): void {
        Route::get('/', [RolePortalAdminController::class, 'index'])
            ->middleware('permission:governance.view');
        Route::post('/', [RolePortalAdminController::class, 'store'])
            ->middleware('permission:governance.approve');
        Route::patch('/{record}', [RolePortalAdminController::class, 'update'])
            ->middleware('permission:governance.approve');
        Route::delete('/{record}', [RolePortalAdminController::class, 'destroy'])
            ->middleware('permission:governance.approve');
    });

    Route::get(
        '/delivery/orders/{order}/journey',
        [OrderJourneyAdminController::class, 'journey'],
    );

    /* ZAD_FINAL_DELIVERY_V2 */
    Route::patch(
        '/delivery/orders/{order}/feedback/{feedback}',
        [OrderJourneyAdminController::class, 'updateFeedback'],
    )->middleware('throttle:30,1');

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
        '/delivery/orders/{order}/retention/schedule-at',
        [OrderJourneyAdminController::class, 'scheduleAt'],
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
        Route::get('/overview', [FamilyOrderController::class, 'overview']);
        Route::get('/orders', [FamilyOrderController::class, 'index']);
        Route::get('/orders/{order}', [FamilyOrderController::class, 'show']);
        Route::post('/orders/{order}/transition', [FamilyOrderController::class, 'transition']);
        Route::post(
            '/orders/{order}/ready-proof',
            [FamilyOrderController::class, 'readyProof'],
        )->middleware('throttle:10,1');

        Route::get('/products', [FamilyProductController::class, 'index']);
        Route::post('/products', [FamilyProductController::class, 'store'])
            ->middleware('throttle:10,1');
        Route::put('/products/{product}', [FamilyProductController::class, 'update']);
        Route::patch(
            '/products/{product}/availability',
            [FamilyProductController::class, 'availability'],
        );
        Route::get(
                    '/orders/{order}/messages',
                   [OrderChatController::class, 'familyIndex'],
       );

        Route::post(
               '/orders/{order}/messages',
              [OrderChatController::class, 'familyStore'],
            )->middleware('throttle:30,1');

        Route::post(
            '/products/{product}/image',
            [FamilyProductController::class, 'uploadImage'],
        )->middleware('throttle:10,1');
        Route::delete('/products/{product}', [FamilyProductController::class, 'destroy']);

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
    /*
    |--------------------------------------------------------------------------
    | ظ…ظ„ظپ ط§ظ„ظ…ظ†ط¯ظˆط¨
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/profile',
        [DriverProfileController::class, 'show'],
    );

    Route::get(
             '/orders/{order}/messages',
        [OrderChatController::class, 'driverIndex'],
    );

    Route::post(
         '/orders/{order}/messages',
       [OrderChatController::class, 'driverStore'],
     )->middleware('throttle:30,1');

    Route::post(
        '/profile',
        [DriverProfileController::class, 'store'],
    )->middleware('throttle:5,1');

    Route::get(
        '/profile-fields',
        [DriverProfileController::class, 'fields'],
    );

    /*
    |--------------------------------------------------------------------------
    | ط§ظ„طھظˆظپط± ظˆط§ظ„ظ…ظˆظ‚ط¹
    |--------------------------------------------------------------------------
    */

    Route::patch(
        '/availability',
        [DriverOrderController::class, 'availability'],
    )->middleware('throttle:20,1');

    Route::post(
        '/location',
        [DriverOrderController::class, 'location'],
    )->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | ظ…ظ‡ط§ظ… ط§ظ„ظ…ظ†ط¯ظˆط¨
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/orders',
        [DriverOrderController::class, 'index'],
    );

    Route::get(
        '/orders/{order}',
        [DriverOrderController::class, 'show'],
    );

    Route::post(
        '/orders/{order}/pickup',
        [DriverOrderController::class, 'pickup'],
    )->middleware('throttle:10,1');

    Route::post(
        '/orders/{order}/start-delivery',
        [DriverOrderController::class, 'startDelivery'],
    )->middleware('throttle:20,1');

    Route::post(
        '/orders/{order}/arrive-customer',
        [DriverOrderController::class, 'arriveAtCustomer'],
    )->middleware('throttle:10,1');

    /* ZAD_DELIVERY_OTP_DRIVER_ROUTE_V1 */
    Route::post(
        '/orders/{order}/verify-delivery-code',
        [DriverOrderController::class, 'verifyDeliveryCode'],
    )->middleware('throttle:5,1');
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
        Route::get('/settlements/settings', [FinanceController::class, 'settlementSettings']);
        Route::put('/settlements/settings', [FinanceController::class, 'updateSettlementSettings']);
        Route::get('/settlements/export', [FinanceController::class, 'exportSettlements']);
        Route::get('/settlements', [FinanceController::class, 'settlements']);
        Route::post('/settlements/{settlement}/release', [FinanceController::class, 'releaseSettlement']);
        Route::post('/settlements/{settlement}/hold', [FinanceController::class, 'holdSettlement']);
    });
     
    Route::prefix('admin/drivers')->group(function (): void {
        Route::get('/stats', [DriverController::class, 'stats']);
        Route::get('/export', [DriverController::class, 'export']);

        Route::get('/', [DriverController::class, 'index']);
        Route::post('/', [DriverController::class, 'store']);

        Route::get(
            '/{driver}',
            [DriverController::class, 'show'],
        );

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
    | ط¥ط¯ط§ط±ط© ط§ظ„ط­ظ‚ظˆظ„ ظ…طھط§ط­ط© ظپظ‚ط· ظ„ظ„ظ…ط³طھط®ط¯ظ… ط§ظ„ظ…طµط§ط¯ظ‚ ط¹ظ„ظٹظ‡طŒ ظˆظٹظ…ظƒظ† ظ„ط§ط­ظ‚ظ‹ط§
    | ط±ط¨ط·ظ‡ط§ ط¨طµظ„ط§ط­ظٹط© products.fields.manage ط£ظˆ products.fields.view.
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
    | ظ…ط³ط§ط±ط§طھ ط¥ط¯ط§ط±ط© ط§ظ„ط£ط³ط± ط§ظ„ظ…ظ†طھط¬ط© ط§ظ„ظ…طھظˆط§ظپظ‚ط© ظ…ط¹ طµظپط­ط© React.
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

        Route::post('/logo', [StoreController::class, 'uploadLogo']);

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
    | ط¥ط¯ط§ط±ط© ظ‡ظˆظٹط© ط§ظ„ظ…ظ†طµط© ظˆطµظپط­ط© طھط³ط¬ظٹظ„ ط§ظ„ط¯ط®ظˆظ„.
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
        Route::any('/{identity}/{record?}/{action?}', \App\Http\Controllers\Api\AdminIdentityController::class)
            ->where('identity', 'users|roles')->whereNumber('record')
            ->name('api.admin.identity');

        /*
        |------------------------------------------------------------------
        | Real Wallet Operations
        |------------------------------------------------------------------
        |
        | These routes must remain above the generic /{resource} routes.
        | They read the real wallets, transactions and order settlements.
        |
        */
        Route::get('/wallets/overview', [AdminWalletController::class, 'overview']);
        Route::get('/wallets', [AdminWalletController::class, 'index']);
        Route::get('/wallets/{wallet}', [AdminWalletController::class, 'show'])
            ->whereNumber('wallet');
        Route::get('/wallets/{wallet}/transactions', [AdminWalletController::class, 'transactions'])
            ->whereNumber('wallet');
        Route::post('/wallets/{wallet}/adjust', [AdminWalletController::class, 'adjust'])
            ->whereNumber('wallet');
        Route::patch('/wallets/{wallet}/status', [AdminWalletController::class, 'updateStatus'])
            ->whereNumber('wallet');

        Route::get('/payout-requests', [PayoutAdminController::class, 'index']);
        Route::get('/payout-requests/{payout}', [PayoutAdminController::class, 'show'])->whereNumber('payout');
        Route::patch('/payout-requests/{payout}/decision', [PayoutAdminController::class, 'decide'])->whereNumber('payout');
        Route::get('/dashboard', [AdminResourceController::class, 'dashboard']);
        Route::get('/dashboard/export', [AdminResourceController::class, 'dashboardExport']);

        Route::get('/settings', [AdminResourceController::class, 'settings']);
        Route::put('/settings', [AdminResourceController::class, 'saveSettings']);

        /*
        |--------------------------------------------------------------------------
        | Driver Registration Field Settings
        |--------------------------------------------------------------------------
        |
        | ط¥ط¯ط§ط±ط© ط§ظ„ط£ط³ط¦ظ„ط© ط§ظ„ط¥ط¶ط§ظپظٹط© ظپظٹ ظ†ظ…ظˆط°ط¬ ط§ظ„ظ…ظ†ط¯ظˆط¨. ظٹط¬ط¨ ط£ظ† طھط¨ظ‚ظ‰ ظ‡ط°ظ‡ ط§ظ„ظ…ط³ط§ط±ط§طھ
        | ظ‚ط¨ظ„ ظ…ط³ط§ط±ط§طھ /{resource} ط§ظ„ط¯ظٹظ†ط§ظ…ظٹظƒظٹط© ط§ظ„ظ…ظˆط¬ظˆط¯ط© ظپظٹ ط£ط³ظپظ„ ط§ظ„ظ…ط¬ظ…ظˆط¹ط©.
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
        | ظ‡ط°ظ‡ ط§ظ„ظ…ط³ط§ط±ط§طھ ظ…ط­ظ…ظٹط© ط¯ط§ط®ظ„ LiveBroadcastAdminControllerطŒ ظˆظ„ط§ ظٹط³ظ…ط­
        | ط¨طھظ†ظپظٹط°ظ‡ط§ ط¥ظ„ط§ ظ„ظ…ط§ظ„ظƒ ط§ظ„ظ…ظ†طµط©. ظٹط¬ط¨ ط£ظ† طھط¨ظ‚ظ‰ ظ‚ط¨ظ„ ظ…ط³ط§ط±ط§طھ {resource}
        | ط§ظ„ط¯ظٹظ†ط§ظ…ظٹظƒظٹط© ط§ظ„ظ…ظˆط¬ظˆط¯ط© ظپظٹ ط£ط³ظپظ„ ظ‡ط°ظ‡ ط§ظ„ظ…ط¬ظ…ظˆط¹ط©.
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

        Route::get('/policy-center', [PolicyCenterController::class, 'index'])
            ->name('api.admin.policy-center.index');

        Route::put('/policy-center/contact', [PolicyCenterController::class, 'updateContact'])
            ->name('api.admin.policy-center.contact.update');

        Route::put('/policy-center/{document}/draft', [PolicyCenterController::class, 'saveDraft'])
            ->whereNumber('document')
            ->name('api.admin.policy-center.draft');

        Route::post('/policy-center/{document}/versions/{version}/publish', [PolicyCenterController::class, 'publish'])
            ->whereNumber('document')
            ->whereNumber('version')
            ->name('api.admin.policy-center.publish');

        Route::get(
            '/platform-settings/meta',
            [PlatformSettingsController::class, 'meta'],
        )->name('api.admin.platform-settings.meta');
        Route::get(
            '/order-chat-retention',
            [OrderChatAdminController::class, 'show'],
             )->name('api.admin.order-chat-retention.show');

        Route::put(
            '/order-chat-retention',
            [OrderChatAdminController::class, 'update'],
              )->name('api.admin.order-chat-retention.update');

        Route::post(
            '/order-chat-retention/purge',
           [OrderChatAdminController::class, 'purge'],
              )->name('api.admin.order-chat-retention.purge');

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
                'options',
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
    Route::get('/categories', [\App\Http\Controllers\Api\App\CategoryCatalogController::class, 'index']);
    Route::get('/categories/{category}/products', [\App\Http\Controllers\Api\App\CategoryCatalogController::class, 'products'])->whereNumber('category');

    Route::post(
        '/promotions/{promotion}/impression',
        [PromotionTrackingController::class, 'impression'],
    )->whereNumber('promotion')->middleware('throttle:120,1');

    Route::post(
        '/promotions/{promotion}/click',
        [PromotionTrackingController::class, 'click'],
    )->whereNumber('promotion')->middleware('throttle:60,1');

    Route::post(
        '/promotions/{promotion}/conversion',
        [PromotionTrackingController::class, 'conversion'],
    )->whereNumber('promotion')->middleware('throttle:30,1');

    Route::post(
        '/auth/google',
        [SocialAuthController::class, 'google'],
    )->middleware('throttle:social-auth');

    Route::post(
        '/phone-verifications/send',
        [PhoneVerificationController::class, 'send'],
    )->middleware('throttle:5,1');

    Route::post(
        '/phone-verifications/verify',
        [PhoneVerificationController::class, 'verify'],
    )->middleware('throttle:10,1');

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

    Route::post(
        '/orders/{order}/confirm-delivery',
        [AppOrderController::class, 'confirmDelivery'],
    )->middleware('throttle:10,1');

    /* ZAD_DELIVERY_OTP_CUSTOMER_ROUTES_V1 */
    Route::get(
        '/orders/{order}/delivery-receipt',
        [AppOrderController::class, 'deliveryReceipt'],
    )->middleware('throttle:30,1');

    Route::post(
        '/orders/{order}/rating',
        [AppOrderController::class, 'rateDelivery'],
    )->middleware('throttle:order-rating');

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
/* ZAD_ACCOUNT_SUPPORT_LANGUAGE_V2 */
Route::post('/v1/app/support/tickets/guest', [\App\Http\Controllers\Api\App\AppSupportTicketController::class, 'guestStore'])
    ->middleware('throttle:10,1')
    ->name('api.app.support.guest.store');

Route::middleware('auth:sanctum')->prefix('v1/app/support')->group(function (): void {
    Route::get('/tickets', [\App\Http\Controllers\Api\App\AppSupportTicketController::class, 'index']);
    Route::post('/tickets', [\App\Http\Controllers\Api\App\AppSupportTicketController::class, 'store']);
    Route::get('/tickets/{ticket}', [\App\Http\Controllers\Api\App\AppSupportTicketController::class, 'show'])->whereNumber('ticket');
    Route::post('/tickets/{ticket}/reply', [\App\Http\Controllers\Api\App\AppSupportTicketController::class, 'reply'])->whereNumber('ticket');
});

require __DIR__.'/zad_payments.php';


\Illuminate\Support\Facades\Route::post(
    'digital-employee-order-tasks',
    [
        \App\Http\Controllers\Api\DigitalEmployeeOrderTaskController::class,
        'store',
    ]
)->middleware([
    'auth:sanctum',
    'throttle:api',
]);

// ZAD_DIGITAL_REPORT_ASSIGNMENTS_V1
Route::middleware(['auth:sanctum', 'throttle:api'])->prefix('digital-report-assignments')->group(function (): void {
    $controller = \App\Http\Controllers\Api\DigitalReportAssignmentController::class;
    Route::get('/', [$controller, 'index']);
    Route::post('/', [$controller, 'store']);
    Route::post('/{id}/change', [$controller, 'change'])->whereNumber('id');
    Route::post('/runs/{id}/review', [$controller, 'review'])->whereNumber('id');
    Route::post('/runs/{id}/retry', [$controller, 'retry'])->whereNumber('id');
    Route::post('/runs/{id}/remind', [$controller, 'remind'])->whereNumber('id');
    Route::get('/runs/{id}/export/{format}', [$controller, 'export'])->whereNumber('id')->whereIn('format', ['pdf', 'xlsx']);
});

// ZAD_ACCOUNT_AVATAR_V1
Route::get('/profile/photo/content', [AuthController::class, 'profilePhotoContent'])
    ->middleware(['auth:sanctum', 'throttle:api']);
