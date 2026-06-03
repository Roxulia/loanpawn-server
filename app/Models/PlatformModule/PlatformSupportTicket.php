<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformSupportTicket extends Model
{
    protected $fillable = [
        'code',
        'platform_user_id',
        'subject',
        'type',
        'status',
        'opened_at',
        'resolved_at',
        'resolved_by',
        'update_key',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'is_deleted' => 'boolean',
        ];
    }

    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PlatformSupportTicketMessage::class);
    }
}
