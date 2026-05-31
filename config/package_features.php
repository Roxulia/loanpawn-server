<?php

return [
    'features' => [
        'tenant_user_management' => [
            'name' => 'Tenant user management',
            'description' => 'Create, update, and remove tenant users.',
        ],
        'customer_management' => [
            'name' => 'Customer management',
            'description' => 'Create, update, and remove tenant customers.',
        ],
        'collateral_management' => [
            'name' => 'Collateral management',
            'description' => 'Create, update, and remove pawn collateral items.',
        ],
        'loan_contract_management' => [
            'name' => 'Loan contract management',
            'description' => 'Create, view, and delete pawn loan contract slips.',
        ],
        'interest_payment_management' => [
            'name' => 'Interest payment management',
            'description' => 'Calculate and receive loan contract interest payments.',
        ],
        'redemption_management' => [
            'name' => 'Redemption management',
            'description' => 'Calculate and process pawn redemptions.',
        ],
        'accounting_management' => [
            'name' => 'Accounting management',
            'description' => 'Manage tenant accounting records.',
        ],
        'expense_management' => [
            'name' => 'Expense management',
            'description' => 'Manage tenant expenses and linked accounting records.',
        ],
        'debt_management' => [
            'name' => 'Debt management',
            'description' => 'Manage tenant debt records.',
        ],
        'online_sync' => [
            'name' => 'Online sync',
            'description' => 'Push offline desktop synchronization logs to the server.',
        ],
        'slip_document_preview' => [
            'name' => 'Slip document preview',
            'description' => 'Preview and download loan contract slip documents.',
        ],
        'slip_document_layout_management' => [
            'name' => 'Slip document layout management',
            'description' => 'Customize loan contract slip header and footer layouts.',
        ],
        'tenant_branding' => [
            'name' => 'Tenant branding',
            'description' => 'Customize tenant logo, favicon, colors, and branding text.',
        ],
    ],

    'packages' => [
        'trial' => [
            'name' => 'Trial',
            'description' => 'Trial package for evaluating the pawnshop system.',
            'price' => 0,
            'is_active' => true,
            'features' => [
                'tenant_user_management',
                'customer_management',
                'collateral_management',
                'loan_contract_management',
                'interest_payment_management',
                'redemption_management',
                'accounting_management',
                'expense_management',
                'debt_management',
                'slip_document_preview',
            ],
        ],
        'basic' => [
            'name' => 'Basic',
            'description' => 'Basic package for growing pawnshops.',
            'price' => 50000,
            'is_active' => true,
            'features' => [
                'tenant_user_management',
                'customer_management',
                'collateral_management',
                'loan_contract_management',
                'interest_payment_management',
                'redemption_management',
                'accounting_management',
                'expense_management',
                'debt_management',
                'online_sync',
                'slip_document_preview',
            ],
        ],
        'premium' => [
            'name' => 'Premium',
            'description' => 'Premium package with branding and advanced features.',
            'price' => 100000,
            'is_active' => true,
            'features' => [
                'tenant_user_management',
                'customer_management',
                'collateral_management',
                'loan_contract_management',
                'interest_payment_management',
                'redemption_management',
                'accounting_management',
                'expense_management',
                'debt_management',
                'online_sync',
                'slip_document_preview',
                'slip_document_layout_management',
                'tenant_branding',
            ],
        ],
    ],
];
