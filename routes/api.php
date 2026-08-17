<?php

use App\Http\Controllers\PawnModule\CollateralItemController;
use App\Http\Controllers\PawnModule\InterestPaymentController;
use App\Http\Controllers\PawnModule\LoanContractSlipController;
use App\Http\Controllers\PawnModule\PawnRedemptionController;
use App\Http\Controllers\PawnModule\SlipDocumentController;
use App\Http\Controllers\PlatformModule\LicenseController;
use App\Http\Controllers\PlatformModule\TelegramWebhookController;
use App\Http\Controllers\PlatformModule\TenantController;
use App\Http\Controllers\TenantModule\Accounting\FinancialAccountTransferController;
use App\Http\Controllers\TenantModule\Accounting\MultiAccountManagement as MultiAccountManagementController;
use App\Http\Controllers\TenantModule\Accounting\ReportingExchangeRateQuoteController;
use App\Http\Controllers\TenantModule\AuthController as TenantAuthController;
use App\Http\Controllers\TenantModule\DefaultDataController;
use App\Http\Controllers\TenantModule\FinancialAccountTypeController;
use App\Http\Controllers\TenantModule\FinancialUnitController;
use App\Http\Controllers\TenantModule\LanguageController;
use App\Http\Controllers\TenantModule\OnlineSyncController;
use App\Http\Controllers\TenantModule\TenantAccountingController;
use App\Http\Controllers\TenantModule\TenantAccountingDayController;
use App\Http\Controllers\TenantModule\TenantBrandingController;
use App\Http\Controllers\TenantModule\TenantCapitalController;
use App\Http\Controllers\TenantModule\TenantCurrencyController;
use App\Http\Controllers\TenantModule\TenantCustomerController;
use App\Http\Controllers\TenantModule\TenantDashboardController;
use App\Http\Controllers\TenantModule\TenantDebtController;
use App\Http\Controllers\TenantModule\TenantExchangeRateController;
use App\Http\Controllers\TenantModule\TenantExchangeRatePairController;
use App\Http\Controllers\TenantModule\TenantExpenseController;
use App\Http\Controllers\TenantModule\TenantRoleController;
use App\Http\Controllers\TenantModule\TenantSettingsController;
use App\Http\Controllers\TenantModule\ReportingCurrencyRateRequirementController;
use App\Http\Controllers\TenantModule\TenantUserController;
use App\Http\Controllers\TenantModule\TenantUserNotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/license/validate', [LicenseController::class, 'validateLicense'])
    ->middleware('throttle:public-api');

Route::post('/telegram/webhook', TelegramWebhookController::class)
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
        Route::get('resolve-tenant', [TenantController::class, 'resolveTenant'])
            ->middleware('throttle:public-api');
        Route::middleware(['auth:sanctum', 'tenant.access', 'tenant.activity', 'throttle:tenant-api'])->group(function () {
            Route::get('me', [TenantAuthController::class, 'me']);
            Route::get('financial-units', [FinancialUnitController::class, 'index']);
            Route::put('me/change-password', [TenantUserController::class, 'changePassword']);
            Route::put('me/change-language', [LanguageController::class, 'change']);
            Route::post('logout', [TenantAuthController::class, 'logout']);
            Route::prefix('notifications')->controller(TenantUserNotificationController::class)->group(function () {
                Route::get('/', 'index');
                Route::post('read-all', 'markAllRead');
                Route::post('{id}/read', 'markRead');
            });
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
                    Route::put('{tenantUserCode}/financial-account-assignments', [TenantUserController::class, 'updateFinancialAccountAssignments'])
                        ->middleware('tenant.permission:manage_financial_account_assignments');
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
                    Route::get('overview', [TenantAccountingController::class, 'overview'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('movements', [TenantAccountingController::class, 'movements'])
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

            Route::prefix('accounting-days')
                ->middleware('tenant.feature:accounting_management')
                ->group(function () {
                    Route::get('/', [TenantAccountingDayController::class, 'index'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::get('current', [TenantAccountingDayController::class, 'current'])
                        ->middleware('tenant.permission:list_accounting');
                    Route::post('open', [TenantAccountingDayController::class, 'open'])
                        ->middleware('tenant.permission:open_accounting_day');
                    Route::post('close', [TenantAccountingDayController::class, 'close'])
                        ->middleware('tenant.permission:close_accounting_day');
                    Route::get('schedule', [TenantAccountingDayController::class, 'schedule'])
                        ->middleware(['tenant.feature:automatic_open_close', 'tenant.permission:open_accounting_day', 'tenant.permission:close_accounting_day']);
                    Route::put('schedule', [TenantAccountingDayController::class, 'updateSchedule'])
                        ->middleware(['tenant.feature:automatic_open_close', 'tenant.permission:open_accounting_day', 'tenant.permission:close_accounting_day']);
                    Route::get('{businessDate}/summary', [TenantAccountingDayController::class, 'show'])
                        ->middleware('tenant.permission:list_accounting');
                });

            Route::prefix('financial-account-types')
                ->controller(FinancialAccountTypeController::class)
                ->middleware('tenant.feature:accounting_management')
                ->group(function () {
                    Route::get('/', 'index')->middleware('tenant.permission:list_financial_account_type');
                    Route::post('/', 'store')->middleware(['tenant.any-feature:accounting_type_management,master_data_management', 'tenant.permission:create_financial_account_type']);
                    Route::put('{code}', 'update')->middleware(['tenant.any-feature:accounting_type_management,master_data_management', 'tenant.permission:update_financial_account_type']);
                    Route::delete('{code}', 'destroy')->middleware(['tenant.any-feature:accounting_type_management,master_data_management', 'tenant.permission:delete_financial_account_type']);
                });

            Route::get('accounting/reporting-exchange-rate-quote', ReportingExchangeRateQuoteController::class)
                ->middleware('tenant.feature:currency_management');

            Route::prefix('financial-accounts')
                ->controller(MultiAccountManagementController::class)
                ->middleware('tenant.feature:multi_account_management')
                ->group(function () {
                    Route::get('/', 'index')->middleware('tenant.permission:list_financial_account');
                    Route::post('/', 'store')->middleware('tenant.permission:create_financial_account');
                    Route::get('transfers', [FinancialAccountTransferController::class, 'index'])->middleware(['tenant.feature:account_transferable', 'tenant.permission:transfer_financial_account']);
                    Route::post('transfers', [FinancialAccountTransferController::class, 'store'])->middleware(['tenant.feature:account_transferable', 'tenant.permission:transfer_financial_account']);
                    Route::get('{accountCode}/transactions', 'transactions')->middleware('tenant.permission:list_financial_account');
                    Route::get('{accountCode}', 'show')->middleware('tenant.permission:list_financial_account');
                    Route::put('{accountCode}', 'update')->middleware('tenant.permission:update_financial_account');
                    Route::delete('{accountCode}', 'destroy')->middleware('tenant.permission:delete_financial_account');
                });

            Route::middleware('tenant.feature:currency_management')->group(function () {
                Route::prefix('currencies')->controller(TenantCurrencyController::class)->group(function () {
                    Route::get('/', 'index')->middleware('tenant.permission:list_currency');
                    Route::post('/', 'store')->middleware('tenant.permission:create_currency');
                    Route::get('{code}', 'show')->middleware('tenant.permission:list_currency');
                    Route::put('{code}', 'update')->middleware('tenant.permission:update_currency');
                    Route::delete('{code}', 'destroy')->middleware('tenant.permission:delete_currency');
                });
                Route::prefix('exchange-pairs')->controller(TenantExchangeRatePairController::class)->middleware('tenant.feature:exchange_pair_management')->group(function () {
                    Route::get('/', 'index')->middleware('tenant.permission:list_exchange_pair');
                    Route::post('/', 'store')->middleware('tenant.permission:create_exchange_pair');
                    Route::get('{code}', 'show')->middleware('tenant.permission:list_exchange_pair');
                    Route::put('{code}', 'update')->middleware('tenant.permission:update_exchange_pair');
                    Route::delete('{code}', 'destroy')->middleware('tenant.permission:delete_exchange_pair');
                });
                Route::prefix('exchange-rates')->controller(TenantExchangeRateController::class)->middleware(['tenant.feature:exchange_pair_management', 'tenant.feature:daily_rate_assignment'])->group(function () {
                    Route::get('/', 'index')->middleware('tenant.permission:list_exchange_rate');
                    Route::post('/', 'store')->middleware('tenant.permission:create_exchange_rate');
                    Route::get('daily', 'daily')->middleware('tenant.permission:list_exchange_rate');
                    Route::get('state', 'state')->middleware('tenant.permission:list_exchange_rate');
                    Route::get('trend', 'trend')->middleware('tenant.permission:list_exchange_rate');
                    Route::get('resolve', 'resolve')->middleware('tenant.permission:list_exchange_rate');
                    Route::get('{code}', 'show')->middleware('tenant.permission:list_exchange_rate');
                    Route::post('{code}/correct', 'correct')->middleware('tenant.permission:correct_exchange_rate');
                    Route::post('{code}/void', 'void')->middleware('tenant.permission:void_exchange_rate');
                });
            });

            Route::prefix('expenses')
                ->middleware('tenant.feature:expense_management')
                ->group(function () {
                    Route::get('/', [TenantExpenseController::class, 'index'])
                        ->middleware('tenant.permission:list_expense');
                    Route::post('/', [TenantExpenseController::class, 'store'])
                        ->middleware('tenant.permission:create_expense');
                    Route::get('{expenseCode}', [TenantExpenseController::class, 'show'])
                        ->middleware('tenant.permission:list_expense');
                    Route::put('{expenseCode}', [TenantExpenseController::class, 'update'])
                        ->middleware('tenant.permission:update_expense');
                    Route::delete('{expenseCode}', [TenantExpenseController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_expense');
                });

            Route::prefix('capitals')
                ->middleware('tenant.feature:capital_management')
                ->group(function () {
                    Route::get('/', [TenantCapitalController::class, 'index'])
                        ->middleware('tenant.permission:list_capital');
                    Route::post('/', [TenantCapitalController::class, 'store'])
                        ->middleware('tenant.permission:create_capital');
                    Route::put('{capitalCode}', [TenantCapitalController::class, 'update'])
                        ->middleware('tenant.permission:update_capital');
                    Route::delete('{capitalCode}', [TenantCapitalController::class, 'destroy'])
                        ->middleware('tenant.permission:delete_capital');
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
                Route::get('currencies', [TenantSettingsController::class, 'currencyPreferences'])
                    ->middleware(['tenant.feature:currency_management', 'tenant.permission:list_currency']);
                Route::put('currencies', [TenantSettingsController::class, 'updateCurrencyPreferences'])
                    ->middleware(['tenant.feature:currency_management', 'tenant.permission:update_currency']);
                Route::get('timezone', [TenantSettingsController::class, 'timezone'])
                    ->middleware(['tenant.feature:tenant_timezone_management', 'tenant.permission:manage_tenant_timezone']);
                Route::get('timezone-options', [TenantSettingsController::class, 'timezoneOptions'])
                    ->middleware(['tenant.feature:tenant_timezone_management', 'tenant.permission:manage_tenant_timezone']);
                Route::put('timezone', [TenantSettingsController::class, 'updateTimezone'])
                    ->middleware(['tenant.feature:tenant_timezone_management', 'tenant.permission:manage_tenant_timezone']);
                Route::prefix('reporting-currency-rate-requirements')
                    ->controller(ReportingCurrencyRateRequirementController::class)
                    ->middleware(['tenant.feature:currency_management', 'tenant.feature:exchange_pair_management', 'tenant.feature:daily_rate_assignment'])
                    ->group(function () {
                        Route::get('/', 'index')->middleware(['tenant.permission:update_currency', 'tenant.permission:list_exchange_rate']);
                        Route::post('/', 'store')->middleware(['tenant.permission:update_currency', 'tenant.permission:create_exchange_rate']);
                    });
                Route::post('reporting-currency-recalculation/abort', [ReportingCurrencyRateRequirementController::class, 'abort'])
                    ->middleware(['tenant.feature:currency_management', 'tenant.permission:update_currency']);
            });

            Route::prefix('slip-documents')
                ->middleware('tenant.feature:slip_document_layout_management')
                ->group(function () {
                    Route::get('config', [SlipDocumentController::class, 'config'])
                        ->middleware('tenant.permission:list_loan_contract');
                });
            Route::prefix('expense-types')
                ->group(function () {
                    Route::get('/', [DefaultDataController::class, 'getExpenseTypes'])
                        ->middleware('tenant.permission:list_expense_type');
                    Route::get('paginated', [DefaultDataController::class, 'getPaginatedExpenseTypes'])
                        ->middleware('tenant.permission:list_expense_type');
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantExpenseType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:create_expense_type']);
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantExpenseType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:delete_expense_type']);
                });
            Route::prefix('interest-types')
                ->group(function () {
                    Route::get('/', [DefaultDataController::class, 'getInterestTypes'])
                        ->middleware('tenant.permission:list_interest_type');
                    Route::get('paginated', [DefaultDataController::class, 'getPaginatedInterestTypes'])
                        ->middleware('tenant.permission:list_interest_type');
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantInterestType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:create_interest_type']);
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantInterestType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:delete_interest_type']);
                });
            Route::prefix('material-types')
                ->group(function () {
                    Route::get('/', [DefaultDataController::class, 'getMaterialTypes'])
                        ->middleware('tenant.permission:list_material_type');
                    Route::get('paginated', [DefaultDataController::class, 'getPaginatedMaterialTypes'])
                        ->middleware('tenant.permission:list_material_type');
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantMaterialType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:create_material_type']);
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantMaterialType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:delete_material_type']);
                });
            Route::prefix('item-category-types')
                ->group(function () {
                    Route::get('/', [DefaultDataController::class, 'getItemCategoryTypes'])
                        ->middleware('tenant.permission:list_item_category_type');
                    Route::get('paginated', [DefaultDataController::class, 'getPaginatedItemCategoryTypes'])
                        ->middleware('tenant.permission:list_item_category_type');
                    Route::post('/', [DefaultDataController::class, 'createCurrentTenantItemCategoryType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:create_item_category_type']);
                    Route::delete('/{code}', [DefaultDataController::class, 'deleteCurrentTenantItemCategoryType'])
                        ->middleware(['tenant.feature:master_data_management', 'tenant.permission:delete_item_category_type']);
                });
            Route::prefix('user-roles')
                ->group(function () {
                    Route::get('/', [TenantRoleController::class, 'index'])
                        ->middleware('tenant.permission:create_user,update_user_admin,update_user_all');
                });
        });
    });
});
