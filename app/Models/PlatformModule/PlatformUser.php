<?php

namespace App\Models\PlatformModule;

use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformUser extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    private const DEFAULT_PREFER_LANG = 'mm';

    protected static function booted(): void
    {
        static::creating(function (PlatformUser $user): void {
            if (! is_string($user->prefer_lang) || $user->prefer_lang === '') {
                $user->prefer_lang = self::DEFAULT_PREFER_LANG;
            }
        });
    }

    protected static function newFactory(): PlatformUserFactory
    {
        return PlatformUserFactory::new();
    }

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
