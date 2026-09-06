<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class TenantUser extends Authenticatable
{
    use BelongToTenant;
    use HasFactory;
    use HasApiTokens;
    use Notifiable;

    protected static function newFactory(): TenantUserFactory
    {
        return TenantUserFactory::new();
    }

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
        'prefer_lang',
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

    public function financialAccountAssignments(): HasMany
    {
        return $this->hasMany(\App\Models\FinancialAccountAssignment::class, 'assigned_user_id');
    }

    public function financialAccounts(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\FinancialAccount::class,
            'financial_account_assignments',
            'assigned_user_id',
            'financial_account_id'
        )->where('financial_accounts.tenant_id', $this->tenant_id)
            ->where('financial_accounts.is_deleted', false)
            ->withTimestamps();
    }

}
