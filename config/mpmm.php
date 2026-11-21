<?php

return [
    'admin_ids' => env('MPMM_ADMIN_IDS', ''),
    'conversation_api' => [
        'token' => env('MPMM_CONVERSATION_API_TOKEN'),
    ],
    'installments' => [
        'interval_days' => (int) env('MPMM_INSTALLMENT_INTERVAL_DAYS', 7),
        'schedule_type' => env('MPMM_INSTALLMENT_SCHEDULE_TYPE', 'monthly'),
    ],
    'credit_rules_preview' => [
        'pilot_phones' => env('MPMM_CREDIT_RULES_PREVIEW_PHONES', ''),
        'universal_reference_amount' => (float) env('MPMM_CREDIT_UNIVERSAL_REFERENCE_AMOUNT', 50),
    ],
    'rotative_wallet' => [
        // Pilot access is explicit. All other members remain denied until o gestor autorizado enables them.
        'pilot_phones' => env('MPMM_ROTATIVE_WALLET_PILOT_PHONES', ''),
        'manager_phones' => env('MPMM_ROTATIVE_WALLET_MANAGER_PHONES', ''),
    ],
    'conversation_session_idle_minutes' => (int) env('MPMM_CONVERSATION_SESSION_IDLE_MINUTES', 120),
    'cash_out' => [
        'minimum_amount' => (float) env('MPMM_CASH_OUT_MINIMUM_AMOUNT', 0.01),
        'fixed_fee' => (float) env('MPMM_CASH_OUT_FIXED_FEE', 0),
        'percentage_rate' => (float) env('MPMM_CASH_OUT_PERCENTAGE_RATE', 0),
    ],
    'maintenance' => [
        'token' => env('MPMM_MAINTENANCE_TOKEN'),
    ],
];
