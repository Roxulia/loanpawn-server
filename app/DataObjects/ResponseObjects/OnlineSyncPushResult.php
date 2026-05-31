<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class OnlineSyncPushResult extends BaseDataObject
{
    /**
     * @param OnlineSyncLogResult[] $results
     */
    public function __construct(
        public int $received,
        public int $applied,
        public int $skipped,
        public int $failed,
        public array $results,
    ) {
    }
}
