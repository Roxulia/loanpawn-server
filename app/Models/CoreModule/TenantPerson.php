<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Database\Factories\TenantPersonFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantPerson extends Model
{
    use BelongToTenant;
    use SoftDeletes;
    use HasFactory;

    protected static function newFactory(): TenantPersonFactory
    {
        return TenantPersonFactory::new();
    }

    protected $fillable = [
        'tenant_id', 'update_key', 'is_deleted', 'name', 'nrc', 'email', 'phone', 'address', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_deleted' => 'boolean'];
    }

    public function customer(): HasOne
    {
        return $this->hasOne(TenantCustomer::class, 'person_id');
    }

    public function lender(): HasOne
    {
        return $this->hasOne(TenantLender::class, 'person_id');
    }
}
