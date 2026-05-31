<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function packageFeatures(): HasMany
    {
        return $this->hasMany(PackageFeature::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'package_features')
            ->withPivot(['is_enabled', 'value'])
            ->withTimestamps();
    }
}
