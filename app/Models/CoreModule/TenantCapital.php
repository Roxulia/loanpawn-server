<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCapital extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'code',
        'account_id',
        'description',
        'amount',
        'created_by',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }
}
