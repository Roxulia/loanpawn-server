<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantBusinessLoanPaymentAllocation extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'payment_id', 'accrual_id', 'amount'];
}
