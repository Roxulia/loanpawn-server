<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class OnlineSyncLogEntry extends BaseDataObject
{
    public function __construct(
        public ?int $id,
        public string $tableName,
        public string $activityType,
        public ?string $recordId,
        public ?string $recordData,
        public ?string $createdAt,
    ) {
    }
}
