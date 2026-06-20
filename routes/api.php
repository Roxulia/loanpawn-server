<?php

use App\Http\Controllers\PlatformModule\LicenseController;
use App\Http\Controllers\PlatformModule\TenantController;
use App\Http\Controllers\PawnModule\CollateralItemController;
use App\Http\Controllers\PawnModule\InterestPaymentController;
use App\Http\Controllers\PawnModule\LoanContractSlipController;
use App\Http\Controllers\PawnModule\PawnRedemptionController;
use App\Http\Controllers\PawnModule\SlipDocumentController;
use App\Http\Controllers\TenantModule\AuthController as TenantAuthController;
use App\Http\Controllers\TenantModule\OnlineSyncController;
use App\Http\Controllers\TenantModule\TenantAccountingController;
use App\Http\Controllers\TenantModule\TenantBrandingController;
use App\Http\Controllers\TenantModule\TenantCustomerController;
use App\Http\Controllers\TenantModule\TenantDashboardController;
use App\Http\Controllers\TenantModule\TenantDebtController;
use App\Http\Controllers\TenantModule\TenantExpenseController;
use App\Http\Controllers\TenantModule\TenantSettingsController;
use App\Http\Controllers\TenantModule\TenantRoleController;
use App\Http\Controllers\TenantModule\TenantUserController;
use App\Http\Controllers\TenantModule\DefaultDataController;
use App\Http\Controllers\TenantModule\LanguageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/license/validate', [LicenseController::class, 'validateLicense'])
    ->middleware('throttle:public-api');

Route::prefix('tenant')->group(function () {
    Route::post('login/public-spa', [TenantAuthController::class, 'loginPublicSpa'])
        ->middleware('throttle:public-api');
    Route::post('sso/consume', [TenantAuthController::class, 'consumeSso'])
        ->middleware('throttle:public-api');
    Route::get('{tenantCode}/logo', [TenantController::class, 'showTenantLogo'])
        ->middleware('throttle:public-api')
        ->name('api.tenant.logo.show');

    Route::middleware('tenant-resolve')->group(function () {
        Route::post('login/subdomain-spa', [TenantAuthController::class, 'loginSubdomainSpa'])
            ->middleware('throttle:public-api');
        Route::get('resolve-tenant',[TenantController::class,'resolveTenant'])
            ->middleware('throttle:public-api');
        Route::middleware(['auth:sanctum', 'tenant.access', 'throttle:tenant-api'])->group(function () {
            Route::get('me', [TenantAuthController::class, 'me']);
            Route::put('me/change-password', [TenantUserController::class, 'changePassword']);
            Route::put('me/change-language', [LanguageController::class, 'change']);
            Route::post('logout', [TenantAuthController::class, 'logout']);
            Route::post('online-sync', [OnlineSyncController::class, 'push'])
                ->middleware('tenant.feature:online_sync')
                ->middleware('throttle:online-sync');
            Route::prefix('dashboard')
                ->middleware('tenant.feature:dashboard')
                ->group(function () {
                    Route::get('summary', [TenantDashboardController::class, 'summary'])
                        ->middleware('tenant.permission:dashboard');
                });

            Route::prefix('users')
                ->middleware('tenant.feature:tenant_user_management')
                ->group(function () {
                    Route::get('/', [TenantUserController::class, 'index'])
                        ->middleware('tenant.permission:list_user');
                    Route::post('/', [TenantUserController::class, 'store'])
                        ->middleware('tenant.permission:create_user');
                    Route::get('{tenantUserCode}', [TenantUserController::class, 'show'])
                        ->middleware('tenant.permission:list_user');
                    Route::put('{tenantUserCode}', [TenantUserController::class, 'update'])
                        ->middleware('tenant.permission:update_user_admin,update_user_all,update_user_own');
                    Route::put('{tenantUserCode}/permissions', [TenantUserController::class, 'updatePermissions'])
                        ->middleware('tenant.permission:update_user_admin');
                    Route::put('{tenantUserCode}/reset-to-defaultpassword', [TenantUserController::class, 'resetPasswordToDefault'])
                        ->middleware('tenant.permission:update_user_admin,update_user_all');
                    Route::delete('{tenantUserCode}', [TenantUserController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_user');
                });

            Route::prefix('customers')
                ->middleware('tenant.feature:customer_management')
                ->group(function () {
                    Route::get('/', [TenantCustomerController::class, 'index'])
                        ->middleware('tenant.permission:list_customer');
                    Route::post('/', [TenantCustomerController::class, 'store'])
                        ->middleware('tenant.permission:create_customer');
                    Route::get('{tenantCustomerCode}', [TenantCustomerController::class, 'show'])
                        ->middleware('tenant.permission:list_customer');
                    Route::put('{tenantCustomerCode}', [TenantCustomerController::class, 'update'])
                        ->middleware('tenant.permission:update_customer');
                    Route::delete('{tenantCustomerCode}', [TenantCustomerController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_customer');
                });

            Route::prefix('collateral-items')
                ->middleware('tenant.feature:collateral_management')
                ->group(function () {
                    Route::get('/', [CollateralItemController::class, 'index'])
                        ->middleware('tenant.permission:list_collateral');
                    Route::get('{itemCode}', [CollateralItemController::class, 'show'])
                        ->middleware('tenant.permission:list_collateral');
                    Route::delete('{itemCode}', [CollateralItemController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_collateral');
                });

            Route::prefix('loan-contract-slips')
                ->middleware('tenant.feature:loan_contract_management')
                ->group(function () {
                    Route::get('/', [LoanContractSlipController::class, 'index'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::post('/', [LoanContractSlipController::class, 'store'])
                        ->middleware('tenant.permission:create_loan_contract');
                    Route::get('{slipNo}', [LoanContractSlipController::class, 'show'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::delete('{slipNo}', [LoanContractSlipController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_loan_contract');
                });

            Route::prefix('loan-contract-slips')
                ->middleware('tenant.feature:slip_document_preview')
                ->group(function () {
                    Route::get('{slipNo}/document/preview', [SlipDocumentController::class, 'preview'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::get('{slipNo}/document/download', [SlipDocumentController::class, 'download'])
                        ->middleware('tenant.permission:list_loan_contract');
                });

            Route::prefix('interest-payments')
                ->middleware('tenant.feature:interest_payment_management')
                ->group(function () {
                    Route::get('/', [InterestPaymentController::class, 'history'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::get('{slipNo}/calculate', [InterestPaymentController::class, 'calculate'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::post('{slipNo}/pay', [InterestPaymentController::class, 'pay'])
                        ->middleware('tenant.permission:create_loan_contract');
                });

            Route::prefix('redemptions')
                ->middleware('tenant.feature:redemption_management')
                ->group(function () {
                    Route::get('/', [PawnRedemptionController::class, 'index'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::get('{slipNo}/calculate', [PawnRedemptionController::class, 'calculate'])
                        ->middleware('tenant.permission:list_loan_contract');
                    Route::post('/', [PawnRedemptionController::class, 'store'])
                        ->middleware('tenant.permission:create_loan_contract');
                });

            Route::prefix('redemption-records')
                ->middleware('tenant.feature:redemption_management')
                ->group(function () {
                    Route::get('{slipNumber}', [PawnRedemptionController::class, 'show'])
                        ->middleware('tenant.permission:list_loan_contract');
                });

            Route::prefix('accounting')
                ->middleware('tenant.feature:accounting_management')
                ->group(function () {
                    Route::get('/', [TenantAccountingController::class, 'index'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('incoming', [TenantAccountingController::class, 'listIncomingTransactions'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('outgoing', [TenantAccountingController::class, 'listOutgoingTransactions'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('ledger', [TenantAccountingController::class, 'getAccountingLedger'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('ledger/download', [TenantAccountingController::class, 'downloadAccountingLedger'])
                        ->middleware('tenant.permission:list_accounting');
                });

            Route::prefix('expenses')
                ->middleware('tenant.feature:expense_management')
                ->group(function () {
                    Route::get('/', [TenantExpenseController::class, 'index'])
                        ->middleware('tenant.permission:list_expense');
                    Route::post('/', [TenantExpenseController::class, 'store'])
                        ->middleware('tenant.permission:create_expense');
                    Route::put('{expenseCode}', [TenantExpenseController::class, 'update'])
                        ->middleware('tenant.permission:update_expense');
                    Route::delete('{expenseCode}', [TenantExpenseController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_expense');
                });

            Route::prefix('debts')
                ->middleware('tenant.feature:debt_management')
                ->group(function () {
                    Route::get('/', [TenantDebtController::class, 'index'])
                        ->middleware('tenant.permission:list_debt');
                    Route::post('/', [TenantDebtController::class, 'store'])
                        ->middleware('tenant.permission:create_debt');
                    Route::post('{debtCode}/paid', [TenantDebtController::class, 'markAsPaid'])
                        ->middleware('tenant.permission:update_debt');
                    Route::put('{debtCode}', [TenantDebtController::class, 'update'])
                        ->middleware('tenant.permission:update_debt');
                    Route::delete('{debtCode}', [TenantDebtController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_debt');
                });

            Route::prefix('branding')->group(function () {
                Route::get('slip-layouts', [TenantBrandingController::class, 'showSlipLayouts'])
                    ->middleware('tenant.feature:slip_document_layout_management')
                    ->middleware('tenant.permission:list_loan_contract');
                Route::put('slip-layouts', [TenantBrandingController::class, 'updateSlipLayouts'])
                    ->middleware('tenant.feature:slip_document_layout_management')
                    ->middleware('tenant.permission:manage_slip_document');
            });

            Route::prefix('settings')->group(function () {
                Route::get('/', [TenantSettingsController::class, 'show'])
                    ->middleware('tenant.permission:manage_slip_document');
                Route::put('/', [TenantSettingsController::class, 'update'])
                    ->middleware('tenant.permission:manage_slip_document');
                Route::put('branding', [TenantSettingsController::class, 'updateBranding'])
                    ->middleware('tenant.feature:tenant_branding')
                    ->middleware('tenant.permission:manage_slip_document');
                Route::put('contact', [TenantSettingsController::class, 'updateContact'])
                    ->middleware('tenant.permission:manage_slip_document');
                Route::put('default-user-password', [TenantSettingsController::class, 'updateTenantDefaultUserPassword'])
                    ->middleware('tenant.permission:manage_slip_document');
                Route::post('interest-types', [TenantSettingsController::class, 'createInterestType'])
                    ->middleware('tenant.feature:master_data_management')
                    ->middleware('tenant.permission:manage_slip_document');
                Route::post('expense-types', [TenantSettingsController::class, 'createExpenseType'])
                    ->middleware('tenant.feature:master_data_management')
                    ->middleware('tenant.permission:manage_slip_document');
                Route::post('material-types', [TenantSettingsController::class, 'createMaterialType'])
                    ->middleware('tenant.feature:master_data_management')
                    ->middleware('tenant.permission:manage_slip_document');
            });

            Route::prefix('slip-documents')
                ->middleware('tenant.feature:slip_document_layout_management')
                ->group(function () {
                    Route::get('config', [SlipDocumentController::class, 'config'])
                        ->middleware('tenant.permission:list_loan_contract');
                });
            Route::prefix('expense-types')
                ->middleware('tenant.permission:create_expense,update_expense,manage_slip_document')
                ->group(function(){
                    Route::get('/', [DefaultDataController::class, 'getExpenseTypes']);
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantExpenseType'])
                        ->middleware('tenant.feature:master_data_management');
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantExpenseType'])
                        ->middleware('tenant.feature:master_data_management');
                });
            Route::prefix('interest-types')
                ->middleware('tenant.permission:create_loan_contract,update_loan_contract,manage_slip_document')
                ->group(function(){
                    Route::get('/', [DefaultDataController::class, 'getInterestTypes']);
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantInterestType'])
                        ->middleware('tenant.feature:master_data_management');
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantInterestType'])
                        ->middleware('tenant.feature:master_data_management');
                });
            Route::prefix('material-types')
                ->middleware('tenant.permission:create_collateral,update_collateral,manage_slip_document')
                ->group(function(){
                    Route::get('/', [DefaultDataController::class, 'getMaterialTypes']);
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantMaterialType'])
                        ->middleware('tenant.feature:master_data_management');
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantMaterialType'])
                        ->middleware('tenant.feature:master_data_management');
                });
            Route::prefix('user-roles')
                ->group(function(){
                    Route::get('/', [TenantRoleController::class, 'index'])
                        ->middleware('tenant.permission:create_user,update_user_admin,update_user_all');
                });
        });
    });
});
