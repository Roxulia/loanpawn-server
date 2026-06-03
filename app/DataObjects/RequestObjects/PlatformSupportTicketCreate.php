<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class PlatformSupportTicketCreate extends BaseDataObject
{
    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function __construct(
        public string $subject,
        public string $type,
        public string $message,
        public array $attachments = [],
    ) {
    }
}
