<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class PlatformSupportTicketReply extends BaseDataObject
{
    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function __construct(
        public int $ticketId,
        public string $message,
        public array $attachments = [],
    ) {
    }
}
