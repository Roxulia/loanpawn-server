<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class OnlineSyncPushRequest extends BaseDataObject
{
    /**
     * @param OnlineSyncLogEntry[] $syncLogs
     */
    public function __construct(
        public array $syncLogs,
    ) {
    }
}
