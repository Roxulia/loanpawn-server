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
use App\Http\Controllers\TenantModule\TenantDebtController;
use App\Http\Controllers\TenantModule\TenantExpenseController;
use App\Http\Controllers\TenantModule\TenantSettingsController;
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
            Route::get('users', [TenantUserController::class, 'index'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:list_user');
            Route::post('users', [TenantUserController::class, 'store'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:create_user');
            Route::get('users/{tenantUserCode}', [TenantUserController::class, 'show'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:list_user');
            Route::put('users/{tenantUserCode}', [TenantUserController::class, 'update'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:update_user_admin,update_user_all,update_user_own');
            Route::put('users/{tenantUserCode}/permissions', [TenantUserController::class, 'updatePermissions'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:update_user_admin');
            Route::put('users/{tenantUserCode}/reset-to-defaultpassword', [TenantUserController::class, 'resetPasswordToDefault'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:update_user_admin,update_user_all');
            Route::delete('users/{tenantUserCode}', [TenantUserController::class, 'destroy'])
                ->middleware('tenant.feature:tenant_user_management')
                ->middleware('tenant.permission:delete_user');
            Route::get('customers', [TenantCustomerController::class, 'index'])
                ->middleware('tenant.feature:customer_management')
                ->middleware('tenant.permission:list_customer');
            Route::post('customers', [TenantCustomerController::class, 'store'])
                ->middleware('tenant.feature:customer_management')
                ->middleware('tenant.permission:create_customer');
            Route::get('customers/{tenantCustomerCode}', [TenantCustomerController::class, 'show'])
                ->middleware('tenant.feature:customer_management')
                ->middleware('tenant.permission:list_customer');
            Route::put('customers/{tenantCustomerCode}', [TenantCustomerController::class, 'update'])
                ->middleware('tenant.feature:customer_management')
                ->middleware('tenant.permission:update_customer');
            Route::delete('customers/{tenantCustomerCode}', [TenantCustomerController::class, 'destroy'])
                ->middleware('tenant.feature:customer_management')
                ->middleware('tenant.permission:delete_customer');
            Route::get('collateral-items', [CollateralItemController::class, 'index'])
                ->middleware('tenant.feature:collateral_management')
                ->middleware('tenant.permission:list_collateral');
            Route::get('collateral-items/{itemCode}', [CollateralItemController::class, 'show'])
                ->middleware('tenant.feature:collateral_management')
                ->middleware('tenant.permission:list_collateral');
            Route::delete('collateral-items/{itemCode}', [CollateralItemController::class, 'destroy'])
                ->middleware('tenant.feature:collateral_management')
                ->middleware('tenant.permission:delete_collateral');
            Route::get('loan-contract-slips', [LoanContractSlipController::class, 'index'])
                ->middleware('tenant.feature:loan_contract_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::post('loan-contract-slips', [LoanContractSlipController::class, 'store'])
                ->middleware('tenant.feature:loan_contract_management')
                ->middleware('tenant.permission:create_loan_contract');
            Route::get('loan-contract-slips/{slipNo}', [LoanContractSlipController::class, 'show'])
                ->middleware('tenant.feature:loan_contract_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::delete('loan-contract-slips/{slipNo}', [LoanContractSlipController::class, 'destroy'])
                ->middleware('tenant.feature:loan_contract_management')
                ->middleware('tenant.permission:delete_loan_contract');
            Route::get('interest-payments', [InterestPaymentController::class, 'history'])
                ->middleware('tenant.feature:interest_payment_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::get('interest-payments/{slipNo}/calculate', [InterestPaymentController::class, 'calculate'])
                ->middleware('tenant.feature:interest_payment_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::post('interest-payments/{slipNo}/pay', [InterestPaymentController::class, 'pay'])
                ->middleware('tenant.feature:interest_payment_management')
                ->middleware('tenant.permission:create_loan_contract');
            Route::get('redemptions', [PawnRedemptionController::class, 'index'])
                ->middleware('tenant.feature:redemption_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::get('redemptions/{slipNo}/calculate', [PawnRedemptionController::class, 'calculate'])
                ->middleware('tenant.feature:redemption_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::post('redemptions', [PawnRedemptionController::class, 'store'])
                ->middleware('tenant.feature:redemption_management')
                ->middleware('tenant.permission:create_loan_contract');
            Route::get('redemption-records/{slipNumber}', [PawnRedemptionController::class, 'show'])
                ->middleware('tenant.feature:redemption_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::get('accounting', [TenantAccountingController::class, 'index'])
                ->middleware('tenant.feature:accounting_management')
                ->middleware('tenant.permission:list_accounting');
            Route::get('accounting/incoming', [TenantAccountingController::class, 'listIncomingTransactions'])
                ->middleware('tenant.feature:accounting_management')
                ->middleware('tenant.permission:list_accounting');
            Route::get('accounting/outgoing', [TenantAccountingController::class, 'listOutgoingTransactions'])
                ->middleware('tenant.feature:accounting_management')
                ->middleware('tenant.permission:list_accounting');
            Route::get('accounting/ledger', [TenantAccountingController::class, 'getAccountingLedger'])
                ->middleware('tenant.feature:accounting_management')
                ->middleware('tenant.permission:list_accounting');
            Route::get('accounting/ledger/download', [TenantAccountingController::class, 'downloadAccountingLedger'])
                ->middleware('tenant.feature:accounting_management')
                ->middleware('tenant.permission:list_accounting');
            Route::get('expenses', [TenantExpenseController::class, 'index'])
                ->middleware('tenant.feature:expense_management')
                ->middleware('tenant.permission:list_expense');
            Route::post('expenses', [TenantExpenseController::class, 'store'])
                ->middleware('tenant.feature:expense_management')
                ->middleware('tenant.permission:create_expense');
            Route::put('expenses/{expenseCode}', [TenantExpenseController::class, 'update'])
                ->middleware('tenant.feature:expense_management')
                ->middleware('tenant.permission:update_expense');
            Route::delete('expenses/{expenseCode}', [TenantExpenseController::class, 'destroy'])
                ->middleware('tenant.feature:expense_management')
                ->middleware('tenant.permission:delete_expense');
            Route::get('debts', [TenantDebtController::class, 'index'])
                ->middleware('tenant.feature:debt_management')
                ->middleware('tenant.permission:list_debt');
            Route::post('debts', [TenantDebtController::class, 'store'])
                ->middleware('tenant.feature:debt_management')
                ->middleware('tenant.permission:create_debt');
            Route::post('debts/{debtCode}/paid', [TenantDebtController::class, 'markAsPaid'])
                ->middleware('tenant.feature:debt_management')
                ->middleware('tenant.permission:update_debt');
            Route::put('debts/{debtCode}', [TenantDebtController::class, 'update'])
                ->middleware('tenant.feature:debt_management')
                ->middleware('tenant.permission:update_debt');
            Route::delete('debts/{debtCode}', [TenantDebtController::class, 'destroy'])
                ->middleware('tenant.feature:debt_management')
                ->middleware('tenant.permission:delete_debt');
            Route::get('branding/slip-layouts', [TenantBrandingController::class, 'showSlipLayouts'])
                ->middleware('tenant.feature:slip_document_layout_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::put('branding/slip-layouts', [TenantBrandingController::class, 'updateSlipLayouts'])
                ->middleware('tenant.feature:slip_document_layout_management')
                ->middleware('tenant.permission:manage_slip_document');
            Route::get('settings', [TenantSettingsController::class, 'show'])
                ->middleware('tenant.permission:manage_slip_document');
            Route::put('settings', [TenantSettingsController::class, 'update'])
                ->middleware('tenant.permission:manage_slip_document');
            Route::put('settings/branding', [TenantSettingsController::class, 'updateBranding'])
                ->middleware('tenant.feature:tenant_branding')
                ->middleware('tenant.permission:manage_slip_document');
            Route::put('settings/contact', [TenantSettingsController::class, 'updateContact'])
                ->middleware('tenant.permission:manage_slip_document');
            Route::put('settings/default-user-password', [TenantSettingsController::class, 'updateTenantDefaultUserPassword'])
                ->middleware('tenant.permission:manage_slip_document');
            Route::post('settings/interest-types', [TenantSettingsController::class, 'createInterestType'])
                ->middleware('tenant.feature:master_data_management')
                ->middleware('tenant.permission:manage_slip_document');
            Route::post('settings/expense-types', [TenantSettingsController::class, 'createExpenseType'])
                ->middleware('tenant.feature:master_data_management')
                ->middleware('tenant.permission:manage_slip_document');
            Route::post('settings/material-types', [TenantSettingsController::class, 'createMaterialType'])
                ->middleware('tenant.feature:master_data_management')
                ->middleware('tenant.permission:manage_slip_document');
            Route::get('slip-documents/config', [SlipDocumentController::class, 'config'])
                ->middleware('tenant.feature:slip_document_layout_management')
                ->middleware('tenant.permission:list_loan_contract');
            Route::get('loan-contract-slips/{slipNo}/document/preview', [SlipDocumentController::class, 'preview'])
                ->middleware('tenant.feature:slip_document_preview')
                ->middleware('tenant.permission:list_loan_contract');
            Route::get('loan-contract-slips/{slipNo}/document/download', [SlipDocumentController::class, 'download'])
                ->middleware('tenant.feature:slip_document_preview')
                ->middleware('tenant.permission:list_loan_contract');
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
        });
    });
});
