<?php

namespace App\Models;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountAssignment extends Model
{
    protected $fillable = [
        'tenant_id',
        'financial_account_id',
        'assigned_user_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'assigned_user_id');
    }
}
