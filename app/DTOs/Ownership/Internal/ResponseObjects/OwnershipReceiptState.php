<?php

namespace App\DTOs\Ownership\Internal\ResponseObjects;

use App\Models\InventoryModule\InventoryItem;
use App\Models\OwnershipModule\{AcquisitionLot, OwnedItem};
use App\Models\PurchasingModule\PurchaseReceiptLine;

/** Internal canonical receipt, identity and lot resolved under the operation's shared anchors. */
final readonly class OwnershipReceiptState
{
    public function __construct(public int $tenantId, public PurchaseReceiptLine $receipt,
        public InventoryItem $item, public OwnedItem $owner, public AcquisitionLot $lot) {}
}
