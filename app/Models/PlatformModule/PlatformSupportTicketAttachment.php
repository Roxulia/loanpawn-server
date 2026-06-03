<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSupportTicketAttachment extends Model
{
    protected $fillable = [
        'code',
        'platform_support_ticket_message_id',
        'file_path',
        'file_type',
        'original_name',
        'file_size',
        'uploaded_by_type',
        'uploaded_by_user_id',
        'uploaded_by_admin_id',
        'update_key',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(PlatformSupportTicketMessage::class, 'platform_support_ticket_message_id');
    }

    public function uploaderUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'uploaded_by_user_id');
    }

    public function uploaderAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'uploaded_by_admin_id');
    }
}
