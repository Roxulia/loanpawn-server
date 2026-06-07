<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformUser extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'code',
        'email',
        'phone',
        'password',
        'status',
        'email_verified_at',
        'prefer_lang',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function tenantRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(PlatformSupportTicket::class);
    }

}
