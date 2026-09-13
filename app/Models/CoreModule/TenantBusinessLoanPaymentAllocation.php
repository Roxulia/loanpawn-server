<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Database\Factories\TenantBusinessLoanPaymentAllocationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TenantBusinessLoanPaymentAllocation extends Model
{
    use BelongToTenant;
    use HasFactory;

    protected static function newFactory(): TenantBusinessLoanPaymentAllocationFactory
    {
        return TenantBusinessLoanPaymentAllocationFactory::new();
    }

    protected $fillable = ['tenant_id', 'payment_id', 'accrual_id', 'amount'];
}
