<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class OnlineSyncLogResult extends BaseDataObject
{
    public function __construct(
        public ?int $clientLogId,
        public ?string $tableName,
        public ?string $recordId,
        public string $status,
        public string $message,
        public ?string $serverUpdatedAt = null,
    ) {
    }
}
