<?php

namespace App\Models\PlatformModule;

use App\Models\CoreModule\TenantBranding;
use App\Models\CoreModule\TenantContact;
use App\Models\CoreModule\TenantSetting;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    protected $fillable = [
        'platform_user_id',
        'category_id',
        'name',
        'tenant_code',
        'plan_type',
        'subdomain',
        'status',
        'update_key',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantCategory::class, 'category_id');
    }

    public function license(): HasOne
    {
        return $this->hasOne(TenantLicense::class);
    }

    public function branding(): HasOne
    {
        return $this->hasOne(TenantBranding::class);
    }

    public function contact(): HasOne
    {
        return $this->hasOne(TenantContact::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }
}
