<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_deleted',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class, 'category_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'category_id');
    }
}
