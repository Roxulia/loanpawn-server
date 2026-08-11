<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'code',
        'category_id',
        'rank',
        'is_trial',
        'name',
        'description',
        'price',
        'max_slip_per_month',
        'max_staff_count',
        'max_account_count',
        'max_currency_type_count',
        'max_exchange_pair_count',
        'is_active',
        'is_deleted',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'max_slip_per_month' => 'integer',
            'max_staff_count' => 'integer',
            'max_account_count' => 'integer',
            'max_currency_type_count' => 'integer',
            'max_exchange_pair_count' => 'integer',
            'is_active' => 'boolean',
            'is_trial' => 'boolean',
            'rank' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TenantCategory::class, 'category_id');
    }

    public function packageFeatures(): HasMany
    {
        return $this->hasMany(PackageFeature::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class, 'plan_id');
    }

    public function requestedBy(): HasMany
    {
        return $this->hasMany(TenantRequest::class, 'requested_plan_id');
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(TenantLicensePlanTransition::class, 'to_plan_id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'package_features')
            ->withPivot(['is_enabled', 'value'])
            ->withTimestamps();
    }
}
