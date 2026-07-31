<?php
use App\Http\Controllers\Api\App\{AppAuthController,AppBootstrapController,AppCatalogController,AppOrderController,GuestSessionController,JoinController,OrderJourneyController,PhoneVerificationController};
use App\Http\Controllers\Api\Owner\{AppSettingsController,OrderVehicleOverrideController};
use Illuminate\Support\Facades\Route;

Route::prefix('v1/app')->name('app-api.')->middleware('throttle:api')->group(function (): void {
 Route::get('/bootstrap',AppBootstrapController::class)->name('bootstrap');
 Route::post('/guest-sessions',[GuestSessionController::class,'store'])->name('guest.store');
 Route::patch('/guest-sessions/{guest}',[GuestSessionController::class,'update'])->name('guest.update');
 Route::post('/phone/send-code',[PhoneVerificationController::class,'send'])->middleware('throttle:3,1')->name('phone.send');
 Route::post('/phone/verify',[PhoneVerificationController::class,'verify'])->middleware('throttle:6,1')->name('phone.verify');
 Route::post('/auth/register',[AppAuthController::class,'register'])->middleware('throttle:5,1')->name('register');
 Route::post('/auth/login',[AppAuthController::class,'login'])->middleware('throttle:5,1')->name('login');
 Route::get('/stores',[AppCatalogController::class,'stores'])->name('stores'); Route::get('/stores/{store}',[AppCatalogController::class,'store'])->name('stores.show'); Route::get('/stores/{store}/products',[AppCatalogController::class,'products'])->name('stores.products'); Route::get('/products/{product}',[AppCatalogController::class,'product'])->name('products.show');
 Route::post('/orders',[AppOrderController::class,'store'])->name('orders.store'); Route::get('/orders/{order}',[AppOrderController::class,'show'])->name('orders.show'); Route::get('/orders/{order}/journey',[OrderJourneyController::class,'index'])->name('orders.journey');
 Route::middleware('auth:sanctum')->group(function (): void { Route::get('/me',[AppAuthController::class,'me'])->name('me'); Route::post('/auth/logout',[AppAuthController::class,'logout'])->name('logout'); Route::post('/join/productive-family',[JoinController::class,'family'])->name('join.family'); Route::post('/join/driver',[JoinController::class,'driver'])->name('join.driver'); Route::post('/orders/{order}/journey-proof',[OrderJourneyController::class,'store'])->name('orders.journey-proof'); });
});
Route::prefix('v1/owner/app')->name('owner-app-api.')->middleware(['auth:sanctum','permission:settings.manage'])->group(function (): void { Route::get('/settings',[AppSettingsController::class,'index'])->name('settings.index'); Route::put('/settings',[AppSettingsController::class,'update'])->name('settings.update'); Route::put('/orders/{order}/vehicle-override',[OrderVehicleOverrideController::class,'update'])->name('orders.vehicle-override'); });
