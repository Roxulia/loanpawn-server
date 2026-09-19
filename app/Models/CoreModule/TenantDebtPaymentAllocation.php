<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Database\Factories\TenantDebtPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TenantDebtPaymentAllocation extends Model
{
    use BelongToTenant;
    use HasFactory;

    protected static function newFactory(): TenantDebtPaymentAllocationFactory
    {
        return TenantDebtPaymentAllocationFactory::new();
    }

    protected $fillable = ['tenant_id', 'payment_id', 'accrual_id', 'amount'];

    protected function casts(): array { return ['amount' => 'decimal:2']; }
}
