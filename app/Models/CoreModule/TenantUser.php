<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class TenantUser extends Authenticatable
{
    use BelongToTenant;
    use HasApiTokens;
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'code',
        'role_id',
        'username',
        'name',
        'nrc',
        'email',
        'phone',
        'address',
        'password',
        'status',
        'is_deleted',
        'last_login_at',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_deleted' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(TenantRole::class, 'role_id');
    }

    public function permission(): HasOne
    {
        return $this->hasOne(TenantUserPermission::class, 'tenant_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

}
