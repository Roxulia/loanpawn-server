<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformSupportTicketMessage extends Model
{
    protected $fillable = [
        'platform_support_ticket_id',
        'sender_type',
        'platform_user_id',
        'platform_admin_id',
        'message',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(PlatformSupportTicket::class, 'platform_support_ticket_id');
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PlatformSupportTicketAttachment::class);
    }
}
