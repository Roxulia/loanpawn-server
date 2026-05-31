<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualPaymentAttachment extends Model
{
    protected $fillable = [
        'manual_payment_request_id',
        'code',
        'file_path',
        'file_type',
        'uploaded_by',
    ];

    public function manualPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(ManualPaymentRequest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'uploaded_by');
    }

}
