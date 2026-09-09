<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantLender extends Model
{
    use BelongToTenant;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'person_id', 'code', 'update_key', 'is_deleted', 'created_by'];

    protected function casts(): array
    {
        return ['is_deleted' => 'boolean'];
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(TenantPerson::class, 'person_id');
    }

    public function businessLoans(): HasMany
    {
        return $this->hasMany(TenantBusinessLoan::class, 'lender_id');
    }
}
