<?php

return [
    // Exact Ownership helper input validation.
    'balance_invalid' => 'Enter a non-negative ownership balance with at most three decimal places and twenty-one whole digits.',
    'cost_invalid' => 'Enter a non-negative exact cost with at most four decimal places and fourteen whole digits.',
    'not_found' => 'The owned item was not found.',
    'lot_not_found' => 'The acquisition lot was not found.',
    'unsupported_source' => 'Unsupported ownership acquisition source.',
    'inventory_unavailable' => 'The selected Inventory item is unavailable.',
    'source_already_recorded' => 'Ownership has already been recorded for this source.',
    'quantity_positive' => 'Quantity must be greater than zero.',
    'insufficient_lot_balance' => 'The requested quantity exceeds the lot balance available for this operation.',
    'quantity_precision' => 'Quantity must be positive with at most three decimal places.',
    'unique_quantity' => 'A unique item must be acquired one at a time.',
    'serialized_quantity' => 'Serialized ownership quantity must be a whole number.',
    'unique_already_owned' => 'This unique Inventory item already has an active ownership balance.',
    // Payment-qualified acquisition sources.
    'purchase_source_invalid' => 'Select a confirmed purchase source that matches this item.',
    'purchase_not_settled' => 'The purchase must be fully settled before ownership is acquired.',

    // Receipt allocation and exact serialized membership.
    'receipt_link_invalid' => 'The receipt does not match the selected acquisition lot and purchase source.',
    'receipt_link_exceeded' => 'The requested quantity exceeds the receipt or acquisition quantity remaining to link.',
    'receipt_already_linked' => 'This receipt allocation has already been linked.',
    'piece_selection_invalid' => 'Select distinct received serialized pieces matching the linking quantity.',
    'piece_already_owned' => 'A selected serialized piece already belongs to a current ownership lot.',
    'receipt_link_required' => 'The selected goods must be received and linked before sale or pledge.',
    'receipt_link_pending' => 'Received items need linking to their acquisition lot.',

    // Quantity changes must consume only the specified source commitment.
    'commitment_source_invalid' => 'The reservation or pledge does not match this operation source.',
    'commitment_insufficient' => 'The requested quantity exceeds the amount remaining for this source.',

    // Monthly publication validation and recovery.
    'summary_month_invalid' => 'Enter a completed Ownership summary month in YYYY-MM format.',
    'summary_month_incomplete' => 'Ownership summaries can only close completed months.',
    'summary_transaction_invalid' => 'Ownership summaries cannot run inside a business transaction.',
    'summary_inconsistent' => 'Ownership summary quantities are inconsistent. Review the records before retrying.',
];
