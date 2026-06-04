<?php

namespace App\Utility;

enum MessageCode: string
{
    case TenantCreated = 'tenant.created';
    case TenantUpdated = 'tenant.updated';
    case TenantDeleted = 'tenant.deleted';
    case TenantDebtCreated = 'tenant_debt.created';
    case TenantDebtUpdated = 'tenant_debt.updated';
    case TenantDebtDeleted = 'tenant_debt.deleted';
}

class Messages
{

     public function responseMessage(
        MessageCode $code,
        array $params = []
    ): string {
        $locale = app()->getLocale();

        $key = 'app.' . $code->value;

        $translated = trans($key, $params, $locale);

        return $translated === $key
            ? $code->value
            : $translated;
    }
}
