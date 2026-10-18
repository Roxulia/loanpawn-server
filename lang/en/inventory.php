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
];
