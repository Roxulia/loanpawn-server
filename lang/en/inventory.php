<?php

return [
    // Quantity format, precision, and range validation.
    'quantity_invalid' => 'Enter a valid numeric quantity.',
    'quantity_positive' => 'Quantity must be greater than zero.',
    'quantity_precision' => 'Quantity must have at most three decimal places.',
    'quantity_out_of_range' => 'Quantity exceeds the supported range.',

    // Tracking-mode quantity requirements.
    'unique_quantity' => 'Quantity must be exactly one for a unique item.',
    'serialized_quantity' => 'Quantity must be a whole number for serialized items.',

    // Inventory tracking, reference, location, and uniqueness validation.
    'tracking_invalid' => 'Choose a supported inventory tracking mode.',
    'catalog_unavailable' => 'The selected catalog item is unavailable.',
    'tracking_mismatch' => 'Tracking mode must match the selected item.',
    'unit_mismatch' => 'Unit must match the selected item.',
    'unit_unavailable' => 'The selected measurement unit is unavailable.',
    'name_required' => 'Enter an inventory item name of at most 180 characters.',
    'identifiers_not_allowed' => 'Unit identifiers are only valid for serialized inventory.',
    'identifier_count' => 'Provide one identifier entry per serialized unit.',
    'identifier_invalid' => 'Enter valid serialized unit identifiers of at most 120 characters.',
    'identifier_duplicate' => 'Serialized unit identifiers must be unique.',
    'identifier_in_use' => 'A serialized unit identifier is already in use.',
    'location_unavailable' => 'The selected inventory location is unavailable.',
    'location_not_sellable' => 'The selected inventory location does not permit sales.',
    'location_name_in_use' => 'This location name is already in use.',
    'unit_selection_invalid' => 'Select one distinct serialized unit per quantity, and no units for other tracking modes.',
    'item_unavailable' => 'The selected inventory item is unavailable in the current tenant.',

    // Monthly checkpoint boundary validation.
    'summary_month_invalid' => 'Enter a completed Inventory summary month in YYYY-MM format.',
    'summary_month_incomplete' => 'Inventory summaries can only close completed months.',

    // Named resource errors and pre-write name validation.
    'item_not_found' => 'The requested inventory item was not found.',
    'location_not_found' => 'The requested inventory location was not found.',
    'location_name_invalid' => 'Enter a location name of at most 120 characters.',
    // Current locking selection, exact availability, and reservation binding.
    'lock_selection_invalid' => 'Select valid inventory items and locations for the stock operation.',
    'reservation_unavailable' => 'The inventory reservation is missing, inactive, insufficient, or does not match this operation.',
    'serialized_unavailable' => 'A selected serialized item is unavailable, at another location, or reserved for another operation.',
    'stock_inconsistent' => 'Inventory quantities or reservation membership are inconsistent.',
    'stock_insufficient' => 'There is not enough available inventory at this location.',
    // Append-only movement history protection.
    'movement_immutable' => 'Inventory movement history cannot be changed or deleted. Append a corrective movement instead.',
    // Location creation validation for trusted service callers.
    'location_type_invalid' => 'Choose a supported inventory location type.',
    // Location edit restrictions protect custody and outstanding reservations.
    'default_location_must_remain_active' => 'The default inventory location cannot be deactivated.',
    'location_has_active_reservations' => 'The location has active reservations. Release or fulfil them before restricting the location.',
    'location_has_stock' => 'The location still holds inventory. Move or issue all stock before deactivating it.',
    // UNIQUE receipt must not duplicate current physical custody.
    'unique_already_received' => 'This unique item is already held in inventory.',
    // Supported internal issue provenance.
    'issue_context_invalid' => 'The inventory issue source does not match a supported operation.',
];
