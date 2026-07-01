<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformAdmin extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'code',
        'username',
        'email',
        'telegram_chat_id',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function resolvedSupportTickets(): HasMany
    {
        return $this->hasMany(PlatformSupportTicket::class, 'resolved_by');
    }

}
