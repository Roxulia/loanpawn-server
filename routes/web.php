<?php

use App\Http\Controllers\LocaleSetterController;
use App\Http\Controllers\PlatformModule\Admin\AdminBillingManagementController;
use App\Http\Controllers\PlatformModule\Admin\AdminDashboardController;
use App\Http\Controllers\PlatformModule\Admin\AdminIssuedTicketController;
use App\Http\Controllers\PlatformModule\Admin\AdminPackageFlagController;
use App\Http\Controllers\PlatformModule\Admin\AdminPaymentRequestController;
use App\Http\Controllers\PlatformModule\Admin\AdminPaymentQrController;
use App\Http\Controllers\PlatformModule\Admin\AdminPlatformUserController;
use App\Http\Controllers\PlatformModule\Admin\AdminTenantManagementController;
use App\Http\Controllers\PlatformModule\AuthController;
use App\Http\Controllers\PlatformModule\LanguageController;
use App\Http\Controllers\PlatformModule\Web\BillingManagementController;
use App\Http\Controllers\PlatformModule\Web\CustomerServiceController;
use App\Http\Controllers\PlatformModule\Web\DashboardController;
use App\Http\Controllers\PlatformModule\Web\PaymentQrImageController;
use App\Http\Controllers\PlatformModule\Web\TenantManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/locale/{locale}', [LocaleSetterController::class, 'setLocale'])->name('locale.set');

Route::name('admin.')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::get('/admin/login', 'showAdminLogin')->name('login.show');
        Route::post('/admin/login', 'loginAdmin')->name('login.submit');
        Route::get('/admin/change-password', 'showAdminChangePassword')->middleware('auth:platformadmin')->name('password.change');
        Route::post('/admin/change-password', 'changeAdminPassword')->middleware('auth:platformadmin')->name('password.update');
        Route::post('/admin/logout', 'logoutAdmin')->middleware('auth:platformadmin')->name('logout');
    });

    Route::prefix('admin')
        ->middleware(['auth:platformadmin', 'admin.password.changed'])
        ->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
            Route::get('/tenants', [AdminTenantManagementController::class, 'index'])->name('tenants.index');
            Route::resource('platform-users', AdminPlatformUserController::class)
                ->parameters(['platform-users' => 'platformUser'])
                ->except(['show']);
            Route::get('/billing', [AdminBillingManagementController::class, 'index'])->name('billing.index');
            Route::get('/package-flags', [AdminPackageFlagController::class, 'index'])->name('package-flags.index');
            Route::put('/package-flags', [AdminPackageFlagController::class, 'update'])->name('package-flags.update');
            Route::post('/package-flags/plans', [AdminPackageFlagController::class, 'updatePlans'])->name('package-flags.plans.update');
            Route::post('/package-flags/features', [AdminPackageFlagController::class, 'storeFeature'])->name('package-flags.features.store');
            Route::put('/package-flags/features', [AdminPackageFlagController::class, 'updateFeatures'])->name('package-flags.features.update');
            Route::post('/package-flags/feature-assignment', [AdminPackageFlagController::class, 'updateFeatureAssignment'])->name('package-flags.feature-assignment.update');
            Route::get('/payment-requests', [AdminPaymentRequestController::class, 'index'])->name('payment-requests.index');
            Route::get('/payment-requests/{paymentRequest}', [AdminPaymentRequestController::class, 'show'])->name('payment-requests.show');
            Route::get('/payment-requests/{paymentRequest}/attachments/{attachment}/download', [AdminPaymentRequestController::class, 'downloadAttachment'])
                ->middleware('signed')
                ->name('payment-requests.attachments.download');
            Route::post('/payment-requests/{paymentRequest}/accept', [AdminPaymentRequestController::class, 'accept'])->name('payment-requests.accept');
            Route::post('/payment-requests/{paymentRequest}/reject', [AdminPaymentRequestController::class, 'reject'])->name('payment-requests.reject');
            Route::get('/payment-qrs', [AdminPaymentQrController::class, 'index'])->name('payment-qrs.index');
            Route::post('/payment-qrs', [AdminPaymentQrController::class, 'store'])->name('payment-qrs.store');
            Route::post('/payment-qrs/{paymentQr}/activate', [AdminPaymentQrController::class, 'activate'])->name('payment-qrs.activate');
            Route::get('/payment-qrs/{paymentQr}/image', [AdminPaymentQrController::class, 'image'])->name('payment-qrs.image');
            Route::get('/issued-tickets', [AdminIssuedTicketController::class, 'index'])->name('issued-tickets.index');
            Route::get('/issued-tickets/{ticketCode}', [AdminIssuedTicketController::class, 'show'])->name('issued-tickets.show');
            Route::post('/issued-tickets/{ticketCode}/messages', [AdminIssuedTicketController::class, 'reply'])->name('issued-tickets.messages.store');
            Route::post('/issued-tickets/{ticketCode}/status', [AdminIssuedTicketController::class, 'changeStatus'])->name('issued-tickets.status.update');
            Route::post('/issued-tickets/{ticketCode}/open', [AdminIssuedTicketController::class, 'open'])->name('issued-tickets.open');
            Route::post('/issued-tickets/{ticketCode}/resolve', [AdminIssuedTicketController::class, 'resolve'])->name('issued-tickets.resolve');
        });
});

Route::redirect('/admin', '/admin/dashboard');

Route::redirect('/platform-login', '/login')->name('login');

Route::name('platform.')->group(function () {
    Route::controller(AuthController::class)->group(function () {
        Route::get('/login', 'showUserLogin')->name('login.show');
        Route::post('/login', 'loginUser')->name('login.submit');

        Route::get('/register', 'showRegister')->name('register.show');
        Route::post('/register', 'register')->name('register.submit');
        Route::get('/register/verify', 'showRegisterVerify')->name('register.verify');
        Route::post('/register/send-code', 'sendRegisterVerificationCode')->name('register.send-code');
        Route::post('/register/verify-code', 'verifyRegisterCode')->name('register.verify-code');

        Route::get('/forgot-password', 'showForgotPassword')->name('password.forgot');
        Route::post('/forgot-password/send-code', 'sendResetCode')->name('password.send-code');
        Route::post('/forgot-password/verify-code', 'verifyResetCode')->name('password.verify-code');
        Route::post('/forgot-password/reset', 'resetPassword')->name('password.reset');
        Route::post('/forgot-password/cancel', 'cancelReset')->name('password.cancel');
    });

    Route::middleware('auth:platformuser')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/logout', [AuthController::class, 'logoutUser'])->name('logout');
        Route::get('/settings', [LanguageController::class, 'edit'])->name('settings');
        Route::put('/change-password', [AuthController::class, 'changePassword'])->name('password.change');
        Route::put('/change-language', [LanguageController::class, 'change'])->name('language.change');
        Route::prefix('tenants')->name('tenants.')->controller(TenantManagementController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{tenant}/settings', 'edit')->name('edit');
            Route::put('/{tenant}/settings', 'update')->name('update');
            Route::post('/{tenant}/upgrade-request', 'requestPlanChange')->name('upgrade-request');
            Route::post('/{tenant}/extension-request', 'requestLicenseExtension')->name('extension-request');
            Route::post('/{tenant}/open-app', 'openApp')->name('open-app');
        });

        Route::get('/billing', [BillingManagementController::class, 'index'])->name('billing.index');
        Route::post('/billing/tenant-requests/{tenantRequest}/payment', [BillingManagementController::class, 'submitPayment'])->name('billing.payment.submit');
        Route::get('/payment-qrs/{paymentQr}/image', [PaymentQrImageController::class, 'show'])->name('payment-qrs.image');

        Route::prefix('customer-service')->name('customer-service.')->controller(CustomerServiceController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{ticketCode}', 'show')->name('show');
            Route::post('/{ticketCode}/messages', 'reply')->name('messages.store');
        });
    });
});
