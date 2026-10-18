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
];
