<?php

namespace App\Enums;

/**
 * Identifies the trusted workflow requesting an Inventory issue.
 * This value is assigned by the calling service and grants no authorization bypass.
 * Deferred workflows still require their own enabled feature and implementation.
 */
enum InventoryIssueContext: string
{
    // Keep business caller identities distinct from gated standalone operations.
    case Sale = 'SALE';
    case PurchaseReturn = 'PURCHASE_RETURN';
    case PawnRedemption = 'PAWN_REDEMPTION';
    case Manual = 'MANUAL';
}
