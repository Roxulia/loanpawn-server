<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentQrImage extends Model
{
    protected $fillable = [
        'file_path',
        'original_name',
        'mime_type',
        'is_active',
        'activated_at',
        'uploaded_by',
        'update_key',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'uploaded_by');
    }
}
