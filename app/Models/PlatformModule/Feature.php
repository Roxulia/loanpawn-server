<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function packageFeatures(): HasMany
    {
        return $this->hasMany(PackageFeature::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'package_features')
            ->withPivot(['is_enabled', 'value'])
            ->withTimestamps();
    }
}
