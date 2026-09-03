<?php

namespace App\Support;

class TenantPermissionColumns
{
    private const IMPLIED_LIST_PERMISSIONS = [
        'list_user' => ['update_user_roles', 'update_user_info', 'assign_permission', 'delete_user', 'manage_financial_account_assignments'],
        'list_customer' => ['update_customer', 'delete_customer'],
        'list_collateral' => ['update_collateral', 'delete_collateral'],
        'list_expense' => ['update_expense', 'delete_expense'],
        'list_capital' => ['update_capital', 'delete_capital'],
        'list_debt' => ['update_debt', 'delete_debt'],
        'list_loan_contract' => ['delete_loan_contract'],
        'manage_interest_process_settings' => ['manage_slip_compound_schedule', 'compound_slip_interest', 'collect_partial_principal'],
        'list_financial_account_type' => ['update_financial_account_type', 'delete_financial_account_type'],
        'list_financial_account' => ['update_financial_account', 'delete_financial_account', 'manage_financial_account_assignments'],
        'list_material_type' => ['update_material_type', 'delete_material_type'],
        'list_interest_type' => ['update_interest_type', 'delete_interest_type'],
        'list_item_category_type' => ['update_item_category_type', 'delete_item_category_type'],
        'list_expense_type' => ['update_expense_type', 'delete_expense_type'],
        'list_currency' => ['update_default_currency', 'update_reporting_currency', 'update_default_financial_unit'],
    ];

    public static function all(): array
    {
        return array_keys(config('tenant_permissions.codes', []));
    }

    public static function booleanPayload(array $enabledPermissions): array
    {
        $enabledPermissions = array_fill_keys($enabledPermissions, true);
        $payload = [];

        foreach (self::all() as $permission) {
            $payload[$permission] = isset($enabledPermissions[$permission]);
        }

        return $payload;
    }

    public static function enabledFromModel(?object $model): array
    {
        if ($model === null) {
            return [];
        }

        return array_values(array_filter(
            self::all(),
            fn (string $permission): bool => (bool) ($model->{$permission} ?? false)
        ));
    }

    public static function effectivePermissions(array $permissions): array
    {
        $permissionSet = array_fill_keys($permissions, true);

        foreach (self::IMPLIED_LIST_PERMISSIONS as $listPermission => $sourcePermissions) {
            foreach ($sourcePermissions as $sourcePermission) {
                if (isset($permissionSet[$sourcePermission])) {
                    $permissionSet[$listPermission] = true;
                    break;
                }
            }
        }

        return array_values(array_intersect(self::all(), array_keys($permissionSet)));
    }

    public static function normalizePayload(array $permissions): array
    {
        $payload = [];

        foreach (self::all() as $permission) {
            if (array_key_exists($permission, $permissions)) {
                $payload[$permission] = (bool) $permissions[$permission];
            }
        }

        return $payload;
    }
}
